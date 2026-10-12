<?php

namespace App\Services;

use App\Config\GameExamConstants as C;
use App\Support\Clock;

/**
 * Turns DB rows into API payloads with real JSON types (ints, bools, ISO
 * UTC dates) — unlike older endpoints that return numeric strings.
 *
 * Security rule enforced here: student payloads never contain `is_correct`
 * or the character identity before the reveal condition is met.
 */
class GameExamPresenter
{
    // ------------------------------------------------------------ dashboard

    public function examSummary(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'stage_id' => (int) $e['stage_id'],
            'stage_name' => $e['stage_name'] ?? null,
            'headline' => $e['headline'],
            'description' => $e['description'],
            'duration_seconds' => (int) $e['duration_seconds'],
            'status' => $e['status'],
            'reveal_policy' => $e['reveal_policy'],
            'shuffle_choices' => (bool) $e['shuffle_choices'],
            'pass_percentage' => (float) $e['pass_percentage'],
            'available_from' => Clock::toApi($e['available_from']),
            'available_until' => Clock::toApi($e['available_until']),
            'offline_grace_seconds' => (int) $e['offline_grace_seconds'],
            'question_count' => (int) $e['question_count'],
            'max_points' => (int) $e['max_points'],
            'attempts_count' => (int) $e['attempts_count'],
            'is_locked' => (int) $e['attempts_count'] > 0,
            'published_at' => Clock::toApi($e['published_at']),
            'created_by' => (int) $e['created_by'],
            'created_by_name' => $e['created_by_name'] ?? null,
            'created_at' => Clock::toApi($e['created_at']),
            'updated_at' => Clock::toApi($e['updated_at']),
            'deleted_at' => Clock::toApi($e['deleted_at']),
        ];
    }

    public function examDetail(array $exam, array $questions): array
    {
        $out = $this->examSummary($exam);
        $out['questions'] = array_map(function ($q) {
            return array_merge($this->questionContent($q, true), [
                'id' => (int) $q['id'],
                'order_number' => (int) $q['order_number'],
                'choices' => array_map(fn($c) => [
                    'id' => (int) $c['id'],
                    'choice_text' => $c['choice_text'],
                    'is_correct' => (bool) $c['is_correct'],
                ], $q['choices']),
            ]);
        }, $questions);
        return $out;
    }

    public function resultRow(array $a): array
    {
        return array_merge($this->attemptTotals($a), [
            'student' => [
                'id' => (int) $a['student_id'],
                'full_name' => $a['full_name'],
                'email' => $a['email'],
                'phone' => $a['phone'],
                'profile_image' => $a['profile_image'] ?? null,
                'stage_name' => $a['stage_name'] ?? null,
            ],
            'duration_seconds' => $a['duration_seconds'] !== null ? max(0, (int) $a['duration_seconds']) : null,
        ]);
    }

    /** Full attempt review for the dashboard (always revealed). */
    public function attemptDetailAdmin(array $a, array $aqRows, array $choicesByQ, array $answersByQ): array
    {
        $questions = [];
        foreach ($aqRows as $row) {
            $qid = (int) $row['question_id'];
            $questions[] = $this->reviewedQuestion($row, $choicesByQ[$qid] ?? [], $answersByQ[$qid] ?? []);
        }

        return [
            'attempt' => array_merge($this->attemptTotals($a), [
                'duration_seconds' => $a['finished_at'] !== null
                    ? max(0, Clock::parseDb($a['finished_at'])->getTimestamp() - Clock::parseDb($a['started_at'])->getTimestamp())
                    : null,
            ]),
            'student' => [
                'id' => (int) $a['student_id'],
                'full_name' => $a['full_name'],
                'email' => $a['email'],
                'phone' => $a['phone'],
                'profile_image' => $a['profile_image'] ?? null,
                'stage_name' => $a['stage_name'] ?? null,
                'points' => (int) $a['student_points'],
            ],
            'exam' => [
                'id' => (int) $a['exam_id'],
                'headline' => $a['exam_headline'],
                'description' => $a['exam_description'],
                'duration_seconds' => (int) $a['exam_duration_seconds'],
                'question_count' => (int) $a['exam_question_count'],
            ],
            'questions' => $questions,
        ];
    }

    // -------------------------------------------------------------- student

    public function studentExamCard(array $r): array
    {
        $attempt = null;
        if ($r['attempt_id'] !== null) {
            $attempt = [
                'id' => (int) $r['attempt_id'],
                'status' => $r['attempt_status'],
                'started_at' => Clock::toApi($r['attempt_started_at']),
                'expires_at' => Clock::toApi($r['attempt_expires_at']),
                'finished_at' => Clock::toApi($r['attempt_finished_at']),
                'earned_points' => (int) $r['attempt_earned_points'],
                'deducted_points' => (int) $r['attempt_deducted_points'],
                'final_points' => (int) $r['attempt_final_points'],
                'max_points' => (int) $r['attempt_max_points'],
                'percentage' => $r['attempt_percentage'] !== null ? (float) $r['attempt_percentage'] : null,
                'is_passed' => $r['attempt_is_passed'] !== null ? (bool) $r['attempt_is_passed'] : null,
                'grade_key' => $r['attempt_grade_key'],
                'grade_label' => $r['attempt_grade_label'],
            ];
        }

        return [
            'id' => (int) $r['id'],
            'stage_id' => (int) $r['stage_id'],
            'stage_name' => $r['stage_name'] ?? null,
            'headline' => $r['headline'],
            'description' => $r['description'],
            'duration_seconds' => (int) $r['duration_seconds'],
            'question_count' => (int) $r['question_count'],
            'max_points' => (int) $r['max_points'],
            'pass_percentage' => (float) $r['pass_percentage'],
            'published_at' => Clock::toApi($r['published_at']),
            'available_until' => Clock::toApi($r['available_until']),
            'my_attempt' => $attempt,
        ];
    }

    /**
     * Authoritative attempt state shared by start/get/answer/sync/complete.
     * Choices come in the attempt's frozen order (DEC-14).
     */
    public function attemptState(array $a, array $aqRows, array $choicesByQ, array $answersByQ, string $serverNowApi): array
    {
        $reveal = $a['reveal_policy'];
        $questions = [];
        foreach ($aqRows as $row) {
            $qid = (int) $row['question_id'];
            $choices = $this->orderedChoices($row, $choicesByQ[$qid] ?? []);
            $answers = $answersByQ[$qid] ?? [];
            $revealed = $row['state'] === C::Q_COMPLETED
                || ((int) $row['attempts_used'] >= 1 && $reveal === C::REVEAL_AFTER_FIRST_WRONG)
                || $a['status'] !== C::ATTEMPT_IN_PROGRESS;

            $questions[] = array_merge($this->questionContent($row, false), [
                'question_id' => $qid,
                'position' => (int) $row['position'],
                'score' => (int) $row['score'],
                'second_attempt_score' => $row['second_attempt_score'] === null ? (int) $row['score'] : (int) $row['second_attempt_score'],
                'negative_score' => (int) $row['negative_score'],
                'retry_seconds' => (int) $row['retry_seconds'],
                'choices' => array_map(fn($c) => ['id' => (int) $c['id'], 'text' => $c['choice_text']], $choices),
                'state' => $row['state'],
                'result' => $row['result'],
                'attempts_used' => (int) $row['attempts_used'],
                'retry_available_at' => Clock::toApi($row['retry_available_at']),
                'earned_points' => (int) $row['earned_points'],
                'deducted_points' => (int) $row['deducted_points'],
                'my_answers' => array_map(fn($ans) => [
                    'attempt_no' => (int) $ans['attempt_no'],
                    'choice_id' => (int) $ans['choice_id'],
                    'is_correct' => (bool) $ans['is_correct'],
                    'points_delta' => (int) $ans['points_delta'],
                ], $answers),
                'reveal' => $revealed ? $this->revealPayload($row, $choicesByQ[$qid] ?? []) : null,
            ]);
        }

        return [
            'attempt' => $this->attemptTotals($a),
            'questions' => $questions,
            'server_now' => $serverNowApi,
        ];
    }

    /** Result screen payload: totals + every question fully revealed + failed flag. */
    public function attemptResult(array $exam, array $a, array $aqRows, array $choicesByQ, array $answersByQ): array
    {
        $questions = [];
        foreach ($aqRows as $row) {
            $qid = (int) $row['question_id'];
            $questions[] = $this->reviewedQuestion($row, $choicesByQ[$qid] ?? [], $answersByQ[$qid] ?? []);
        }
        $failed = array_values(array_filter($questions, fn($q) => $q['failed']));

        return [
            'exam' => ['id' => (int) $exam['id'], 'headline' => $exam['headline'], 'stage_name' => $exam['stage_name'] ?? null],
            'attempt' => $this->attemptTotals($a),
            'summary' => [
                'questions' => count($questions),
                'correct_first' => count(array_filter($questions, fn($q) => $q['result'] === C::RESULT_CORRECT_FIRST)),
                'correct_second' => count(array_filter($questions, fn($q) => $q['result'] === C::RESULT_CORRECT_SECOND)),
                'wrong_final' => count(array_filter($questions, fn($q) => $q['result'] === C::RESULT_WRONG_FINAL)),
                'unanswered' => count(array_filter($questions, fn($q) => $q['result'] === C::RESULT_UNANSWERED)),
                'failed' => count($failed),
            ],
            'questions' => $questions,
        ];
    }

    // -------------------------------------------------------------- shared

    public function attemptTotals(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'exam_id' => (int) $a['exam_id'],
            'status' => $a['status'],
            'started_at' => Clock::toApi($a['started_at']),
            'expires_at' => Clock::toApi($a['expires_at']),
            'finished_at' => Clock::toApi($a['finished_at']),
            'max_points' => (int) $a['max_points'],
            'earned_points' => (int) $a['earned_points'],
            'deducted_points' => (int) $a['deducted_points'],
            'final_points' => (int) $a['final_points'],
            'percentage' => $a['percentage'] !== null ? (float) $a['percentage'] : null,
            'is_passed' => $a['is_passed'] !== null ? (bool) $a['is_passed'] : null,
            'pass_percentage' => (float) $a['pass_percentage'],
            'grade_key' => $a['grade_key'],
            'grade_label' => $a['grade_label'],
            'correct_count' => (int) $a['correct_count'],
            'failed_count' => (int) $a['failed_count'],
            'answers_count' => (int) $a['answers_count'],
            'reveal_policy' => $a['reveal_policy'],
            'offline_grace_seconds' => (int) $a['offline_grace_seconds'],
            'sync_version' => (int) $a['sync_version'],
        ];
    }

    private function reviewedQuestion(array $row, array $choices, array $answers): array
    {
        $qid = (int) $row['question_id'];
        $result = $row['result'] ?? null;
        $correct = null;
        foreach ($choices as $c) {
            if ((int) $c['is_correct'] === 1) {
                $correct = $c;
            }
        }

        return array_merge($this->questionContent($row, false), [
            'question_id' => $qid,
            'position' => (int) $row['position'],
            'score' => (int) $row['score'],
            'negative_score' => (int) $row['negative_score'],
            'state' => $row['state'],
            'result' => $result,
            'failed' => in_array($result, [C::RESULT_WRONG_FINAL, C::RESULT_UNANSWERED], true)
                || ($result === null && $row['state'] !== C::Q_COMPLETED),
            'attempts_used' => (int) $row['attempts_used'],
            'earned_points' => (int) $row['earned_points'],
            'deducted_points' => (int) $row['deducted_points'],
            'final_question_points' => (int) $row['earned_points'] - (int) $row['deducted_points'],
            'choices' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'text' => $c['choice_text'],
                'is_correct' => (bool) $c['is_correct'],
            ], $this->orderedChoices($row, $choices)),
            'correct_choice' => $correct ? ['id' => (int) $correct['id'], 'text' => $correct['choice_text']] : null,
            'answers' => array_map(fn($ans) => [
                'attempt_no' => (int) $ans['attempt_no'],
                'choice_id' => (int) $ans['choice_id'],
                'choice_text' => $ans['choice_text'] ?? null,
                'is_correct' => (bool) $ans['is_correct'],
                'points_delta' => (int) $ans['points_delta'],
                'answered_at' => Clock::toApi($ans['answered_at']),
                'was_offline' => (bool) $ans['was_offline'],
            ], $answers),
            'reveal' => $this->revealPayload($row, $choices),
        ]);
    }

    /**
     * What the student sees before answering. For character questions the
     * identity (name/image/description) is withheld; only the teacher's
     * optional front design is shown. Transformation faces A/B are part of
     * the question itself, so they are always visible.
     */
    private function questionContent(array $q, bool $admin): array
    {
        $base = [
            'type' => $q['type'],
            'question_text' => $q['question_text'],
            'card' => [
                'front_text' => $q['front_text'],
                'front_image_url' => $q['front_image_url'],
                'back_text' => $q['type'] === C::TYPE_TRANSFORMATION || $admin ? $q['back_text'] : null,
                'back_image_url' => $q['type'] === C::TYPE_TRANSFORMATION || $admin ? $q['back_image_url'] : null,
            ],
        ];
        if ($admin) {
            $base += [
                'score' => (int) $q['score'],
                'second_attempt_score' => $q['second_attempt_score'] === null ? null : (int) $q['second_attempt_score'],
                'negative_score' => (int) $q['negative_score'],
                'retry_seconds' => (int) $q['retry_seconds'],
                'character_name' => $q['character_name'],
                'character_description' => $q['character_description'],
                'character_image_url' => $q['character_image_url'],
                'info_text' => $q['info_text'],
            ];
        }
        return $base;
    }

    private function revealPayload(array $row, array $choices): array
    {
        $correctId = null;
        $correctText = null;
        foreach ($choices as $c) {
            if ((int) $c['is_correct'] === 1) {
                $correctId = (int) $c['id'];
                $correctText = $c['choice_text'];
            }
        }
        return [
            'correct_choice_id' => $correctId,
            'correct_choice_text' => $correctText,
            'character_name' => $row['character_name'],
            'character_description' => $row['character_description'],
            'character_image_url' => $row['character_image_url'],
            'back_text' => $row['back_text'],
            'back_image_url' => $row['back_image_url'],
            'info_text' => $row['info_text'],
        ];
    }

    private function orderedChoices(array $row, array $choices): array
    {
        $order = $row['choice_order'] ? json_decode($row['choice_order'], true) : null;
        if (!is_array($order)) {
            return $choices;
        }
        $byId = [];
        foreach ($choices as $c) {
            $byId[(int) $c['id']] = $c;
        }
        $out = [];
        foreach ($order as $id) {
            if (isset($byId[(int) $id])) {
                $out[] = $byId[(int) $id];
                unset($byId[(int) $id]);
            }
        }
        return array_merge($out, array_values($byId));
    }
}
