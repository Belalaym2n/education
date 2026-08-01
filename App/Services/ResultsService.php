<?php

namespace App\Services;

use App\Contracts\AttemptRepositoryInterface;
use App\Database\Database;
use App\Exceptions\NotFoundException;

class ResultsService
{
    private AttemptRepositoryInterface $attemptRepo;
    private Database $db;

    public function __construct(AttemptRepositoryInterface $attemptRepo, Database $db)
    {
        $this->attemptRepo = $attemptRepo;
        $this->db = $db;
    }

    /**
     * Returns paginated results list for the Results Dashboard, with
     * filters for review status, pass/fail, date range, student search.
     */
    public function getResultsList(
        int $quizId,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $order
    ): array {
        $rows = $this->attemptRepo->paginate($quizId, $filters, $limit, $offset, $sort, $order);
        $total = $this->attemptRepo->count($quizId, $filters);

        return [
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'order' => $order,
            ],
        ];
    }

    /**
     * Returns aggregated insights for the Results Dashboard Insights card
     * (average score, pass rate, completion rate, etc).
     */
    public function getInsights(int $quizId): array
    {
        return $this->attemptRepo->aggregateInsights($quizId);
    }

    /**
     * Returns a student's submission detail: the attempt, all answers with
     * their question text and correct answer (if available), and review
     * status. This is what the Submission Detail modal shows.
     */
    public function getSubmissionDetail(int $attemptId): array
    {
        $attempt = $this->attemptRepo->find($attemptId);
        if (!$attempt) {
            throw new NotFoundException('Attempt');
        }

        $answers = $this->db->query(
            "SELECT
                qaa.id, qaa.question_id, qaa.selected_choice_ids, qaa.answer_text,
                qaa.answer_image_url, qaa.is_correct, qaa.marks_awarded, qaa.teacher_notes,
                qaa.reviewed_at, qaa.reviewed_by,
                qq.type, qq.question_text, qq.image_url, qq.marks, qq.negative_marks,
                qq.explanation, qq.order_number, qq.shuffle_choices,
                u.full_name AS reviewed_by_name
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             LEFT JOIN users u ON u.id = qaa.reviewed_by
             WHERE qaa.attempt_id = ? AND qq.deleted_at IS NULL
             ORDER BY qq.order_number ASC",
            [$attemptId]
        );

        // Fetch choices for choice-based questions.
        $questionIds = array_unique(array_column($answers, 'question_id'));
        $choicesByQuestion = [];

        if (!empty($questionIds)) {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $choices = $this->db->query(
                "SELECT id, question_id, choice_text, image_url, is_correct, order_number
                 FROM quiz_question_choices
                 WHERE question_id IN ($placeholders)
                 ORDER BY order_number",
                $questionIds
            );

            foreach ($choices as $choice) {
                $choicesByQuestion[$choice['question_id']][] = $choice;
            }
        }

        foreach ($answers as &$answer) {
            $answer['choices'] = $choicesByQuestion[$answer['question_id']] ?? [];
        }
        unset($answer);

        return [
            'attempt' => $attempt,
            'answers' => $answers,
        ];
    }

    /**
     * Exports results to Excel. Includes one row per attempt per student,
     * plus summary columns (MCQ score, essay score, total, percentage, status).
     * Can filter by review status (pending essays, completed, etc).
     */
    public function exportToExcel(int $quizId, array $filters): string
    {
        // Fetch all results (no pagination for export).
        $results = $this->attemptRepo->paginate($quizId, $filters, 100000, 0, 'student_name', 'asc');

        // Build CSV rows.
        $headers = [
            'Student ID',
            'Student Name',
            'Attempt #',
            'Submitted At',
            'Duration (min)',
            'MCQ Score',
            'Essay Score',
            'Total Score',
            'Percentage (%)',
            'Status',
            'Review Status',
            'Pending Essays',
        ];

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [
                $result['student_id_display'] ?? '',
                $result['student_name'] ?? '',
                $result['attempt_number'] ?? 1,
                $result['finished_at'] ? (new \DateTime($result['finished_at']))->format('Y-m-d H:i') : 'N/A',
                $result['duration_used_seconds'] ? round($result['duration_used_seconds'] / 60) : 0,
                $result['mcq_score'] ?? 0,
                $result['essay_score'] ?? 0,
                $result['total_score'] ?? 0,
                $result['percentage'] ?? 0,
                $result['is_passed'] ? 'Passed' : 'Failed',
                ucfirst(str_replace('_', ' ', $result['review_status'] ?? 'N/A')),
                $result['pending_essay_count'] ?? 0,
            ];
        }

        // Generate CSV content.
        $csv = $this->arrayToCsv([$headers, ...$rows]);

        return $csv;
    }

    /**
     * Generates a simple CSV string from a 2D array. Returns the raw CSV
     * content ready to be downloaded as a file.
     */
    private function arrayToCsv(array $rows): string
    {
        $output = fopen('php://memory', 'r+');

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
