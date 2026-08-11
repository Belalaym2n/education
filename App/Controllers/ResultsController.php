<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\ResultsService;
use App\Services\EssayReviewService;

class ResultsController
{ 
    private ResultsService $resultsService;
    private EssayReviewService $essayService;

    public function __construct(ResultsService $resultsService, EssayReviewService $essayService)
    {
        $this->resultsService = $resultsService;
        $this->essayService = $essayService;
    }

    // GET /quizzes/{quizId}/results  — the Results Dashboard table
    public function index(int $quizId): array
    {
        $filters = [
            'student_id' => $_GET['student_id'] ?? null,
            'search' => $_GET['search'] ?? null,
            'status' => $_GET['status'] ?? null,
            'review_status' => $_GET['review_status'] ?? null,
            'is_passed' => isset($_GET['is_passed']) ? filter_var($_GET['is_passed'], FILTER_VALIDATE_BOOLEAN) : null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
        ];
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = (int) ($_GET['offset'] ?? 0);
        $sort = $_GET['sort'] ?? 'finished_at';
        $order = $_GET['order'] ?? 'desc';

        $result = $this->resultsService->getResultsList($quizId, $filters, $limit, $offset, $sort, $order);

        return [
            'success' => true,
            'message' => 'Results retrieved',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ];
    }

    // GET /quizzes/{quizId}/results/insights  — aggregated stats card
    public function insights(int $quizId): array
    {
        return [
            'success' => true,
            'message' => 'Insights retrieved',
            'data' => $this->resultsService->getInsights($quizId),
        ];
    }

    // GET /attempts/{attemptId}/submission-detail  — the modal view
    public function submissionDetail(int $attemptId): array
    {
        return [
            'success' => true,
            'message' => 'Submission detail retrieved',
            'data' => $this->resultsService->getSubmissionDetail($attemptId),
        ];
    }

    // GET /quizzes/{quizId}/results/export  — CSV download
    public function export(int $quizId): string
    {
        $filters = [
            'status' => $_GET['status'] ?? null,
            'review_status' => $_GET['review_status'] ?? null,
            'is_passed' => isset($_GET['is_passed']) ? filter_var($_GET['is_passed'], FILTER_VALIDATE_BOOLEAN) : null,
        ];
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        $csv = $this->resultsService->exportToExcel($quizId, $filters);

        // Headers for download.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="quiz-results-' . date('Y-m-d-His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        return $csv;
    }

    // GET /quizzes/{quizId}/essays/pending  — the essay review worklist
    public function pendingEssays(int $quizId): array
    {
        return [
            'success' => true,
            'message' => 'Pending essays retrieved',
            'data' => $this->essayService->getPendingEssays($quizId),
        ];
    }

    // GET /attempts/{attemptId}/essays  — essays for a specific submission (detail modal)
    public function attemptEssays(int $attemptId): array
    {
        return [
            'success' => true,
            'message' => 'Attempt essays retrieved',
            'data' => $this->essayService->getAttemptEssaysForReview($attemptId),
        ];
    }

    // PUT /answers/{answerId}/score  — score a single essay
    public function scoreEssay(int $answerId, array $requestData): array
    {
        $validator = new \App\Validators\Validator($requestData);
        $validator->required('marks_awarded');
        $validator->validateNumber('marks_awarded', 0);

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $reviewerId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $teacherNotes = $requestData['teacher_notes'] ?? null;

        return [
            'success' => true,
            'message' => 'Essay scored',
            'data' => $this->essayService->scoreEssay(
                $answerId,
                (float) $requestData['marks_awarded'],
                $teacherNotes,
                $reviewerId
            ),
        ];
    }

    // PATCH /answers/{answerId}/mark-reviewed  — mark essay as "reviewed but no score"
    public function markEssayReviewed(int $answerId, array $requestData): array
    {
        $reviewerId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $teacherNotes = $requestData['teacher_notes'] ?? null;

        return [
            'success' => true,
            'message' => 'Essay marked as reviewed',
            'data' => $this->essayService->markEssayReviewed($answerId, $reviewerId, $teacherNotes),
        ];
    }

    // POST /quizzes/{quizId}/essays/publish-reviews  — publish all completed reviews
    public function publishReviews(int $quizId): array
    {
        $reviewerId = (int) ($_SERVER['auth_user_id'] ?? 0);

        return [
            'success' => true,
            'message' => 'Reviews published',
            'data' => $this->essayService->publishReviews($quizId, $reviewerId),
        ];
    }
}
