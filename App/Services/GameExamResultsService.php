<?php

namespace App\Services;

use App\Config\GameExamConstants as C;
use App\Contracts\GameExamAttemptRepositoryInterface;
use App\Contracts\GameExamRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Support\CairoMonth;
use App\Support\Clock;

/** Dashboard analytics: results table, statistics, attempt detail, monthly Top 5. */
class GameExamResultsService
{
    private GameExamRepositoryInterface $exams;
    private GameExamAttemptRepositoryInterface $attempts;
    private GameExamPresenter $presenter;
    private Clock $clock;

    public function __construct(
        GameExamRepositoryInterface $exams,
        GameExamAttemptRepositoryInterface $attempts,
        GameExamPresenter $presenter,
        Clock $clock
    ) {
        $this->exams = $exams;
        $this->attempts = $attempts;
        $this->presenter = $presenter;
        $this->clock = $clock;
    }

    public function results(int $examId, array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $this->assertExam($examId);
        $page = $this->attempts->results($examId, $filters, $limit, $offset, $sort, $order);
        return [
            'data' => array_map(fn($r) => $this->presenter->resultRow($r), $page['rows']),
            'meta' => ['total' => $page['total'], 'limit' => $limit, 'offset' => $offset, 'sort' => $sort, 'order' => $order],
        ];
    }

    public function statistics(int $examId, array $filters): array
    {
        $exam = $this->assertExam($examId);
        $s = $this->attempts->resultStatistics($examId, $filters);

        $pass = (int) ($s['pass_count'] ?? 0);
        $fail = (int) ($s['fail_count'] ?? 0);
        $questionCount = max(1, (int) $exam['question_count']);

        $difficulty = array_map(function ($q) {
            $finished = (int) $q['finished'];
            return [
                'question_id' => (int) $q['id'],
                'type' => $q['type'],
                'question_text' => $q['question_text'],
                'order_number' => (int) $q['order_number'],
                'finished' => $finished,
                'correct_first' => (int) $q['correct_first'],
                'correct_second' => (int) $q['correct_second'],
                'wrong_final' => (int) $q['wrong_final'],
                'unanswered' => (int) $q['unanswered'],
                'failure_rate' => $finished > 0
                    ? round(((int) $q['wrong_final'] + (int) $q['unanswered']) / $finished * 100, 1)
                    : null,
            ];
        }, $this->attempts->questionDifficulty($examId));

        return [
            'exam' => ['id' => (int) $exam['id'], 'headline' => $exam['headline'], 'max_points' => (int) $exam['max_points'], 'question_count' => (int) $exam['question_count']],
            'attempts' => (int) ($s['attempts'] ?? 0),
            'in_progress' => (int) ($s['in_progress'] ?? 0),
            'finished' => (int) ($s['finished'] ?? 0),
            'avg_points' => $s['avg_points'] !== null ? round((float) $s['avg_points'], 2) : null,
            'max_points' => $s['max_points'] !== null ? (int) $s['max_points'] : null,
            'min_points' => $s['min_points'] !== null ? (int) $s['min_points'] : null,
            'avg_percentage' => $s['avg_percentage'] !== null ? round((float) $s['avg_percentage'], 2) : null,
            'pass_count' => $pass,
            'fail_count' => $fail,
            'pass_rate' => ($pass + $fail) > 0 ? round($pass / ($pass + $fail) * 100, 1) : null,
            'total_earned' => (int) ($s['total_earned'] ?? 0),
            'total_deducted' => (int) ($s['total_deducted'] ?? 0),
            // "Average attempts": answer submissions per question (1.0 = always right first time, 2.0 = always retried).
            'avg_attempts_per_question' => $s['avg_submissions'] !== null ? round((float) $s['avg_submissions'] / $questionCount, 2) : null,
            'avg_duration_seconds' => $s['avg_duration_seconds'] !== null ? (int) round((float) $s['avg_duration_seconds']) : null,
            'questions' => $difficulty,
        ];
    }

    public function attemptDetail(int $examId, int $attemptId): array
    {
        $row = $this->attempts->attemptDetail($attemptId);
        if (!$row || (int) $row['exam_id'] !== $examId) {
            throw new NotFoundException('Attempt');
        }
        $aq = $this->attempts->attemptQuestions($attemptId);
        $choices = $this->attempts->choicesForQuestions(array_map(fn($r) => (int) $r['question_id'], $aq));
        $answers = $this->attempts->answersForAttempt($attemptId);
        return $this->presenter->attemptDetailAdmin($row, $aq, $choices, $answers);
    }

    public function topStudents(?string $month, ?int $stageId, ?int $limit): array
    {
        $month = $month ?: CairoMonth::current($this->clock);
        $range = CairoMonth::range($month);
        $limit = max(1, min(C::TOP_STUDENTS_MAX, $limit ?? C::TOP_STUDENTS_DEFAULT));

        $rows = $this->attempts->topStudents($range['from'], $range['to'], $stageId, $limit);

        $groups = [];
        foreach ($rows as $r) {
            $sid = (int) $r['stage_id'];
            $groups[$sid] ??= ['stage_id' => $sid, 'stage_name' => $r['stage_name'], 'students' => []];
            $groups[$sid]['students'][] = [
                'rank' => (int) $r['rank_no'],
                'student_id' => (int) $r['student_id'],
                'full_name' => $r['full_name'],
                'profile_image' => $r['profile_image'],
                'month_points' => (int) $r['month_points'],
                'exams_count' => (int) $r['exams_count'],
                'last_scored_at' => Clock::toApi($r['last_scored_at']),
            ];
        }

        return [
            'month' => $month,
            'timezone' => CairoMonth::TIMEZONE,
            'limit' => $limit,
            'stages' => array_values($groups),
        ];
    }

    private function assertExam(int $examId): array
    {
        $exam = $this->exams->find($examId, true);
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }
        return $exam;
    }
}
