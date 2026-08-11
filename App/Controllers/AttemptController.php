<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Exceptions\ForbiddenException;
use App\Services\AttemptService;

class AttemptController
{
    private AttemptService $attemptService;

    public function __construct(AttemptService $attemptService)
    {
        $this->attemptService = $attemptService;
    }


    
    private function currentStudentId(): int
    {
        $id = (int) ($_SERVER['auth_student_id'] ?? 0);
        if ($id <= 0) {
            throw new ForbiddenException('Authentication required.');
        }
        return $id;
    }

    // POST /quizzes/{quizId}/attempts
    public function start(int $quizId): array
    {
        $studentId = $this->currentStudentId();

        return [
            'success' => true,
            'message' => 'Attempt started',
            'data' => $this->attemptService->startAttempt($quizId, $studentId),
        ];
    }

    // GET /attempts/{attemptId}
    public function show(int $attemptId): array
    {
        $studentId = $this->currentStudentId();

        return [
            'success' => true,
            'message' => 'Attempt loaded',
            'data' => $this->attemptService->getAttempt($attemptId, $studentId),
        ];
    }

    // PUT /attempts/{attemptId}/answers/{questionId}
    public function saveAnswer(int $attemptId, int $questionId, array $requestData): array
    {
        $studentId = $this->currentStudentId();

        $validator = new \App\Validators\Validator($requestData);

        $hasAnswer = array_key_exists('selected_choice_ids', $requestData)
            || array_key_exists('answer_text', $requestData)
            || array_key_exists('answer_image_url', $requestData);

        if (!$hasAnswer) {
            throw new ValidationException(['answer' => 'At least one answer field is required.']);
        }

        if (isset($requestData['answer_text'])) {
            $validator->validateString('answer_text', 1, 5000);
        }

        if (isset($requestData['answer_image_url'])) {
            $validator->validateURL('answer_image_url');
        }

        if (isset($requestData['selected_choice_ids']) && !is_array($requestData['selected_choice_ids'])) {
            $validator->addError('selected_choice_ids', 'Must be an array.');
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        return [
            'success' => true,
            'message' => 'Answer saved',
            'data' => $this->attemptService->saveAnswer($attemptId, $questionId, $requestData, $studentId),
        ];
    }

    // POST /attempts/{attemptId}/submit
    public function submit(int $attemptId): array
    {
        $studentId = $this->currentStudentId();

        return [
            'success' => true,
            'message' => 'Attempt submitted successfully',
            'data' => $this->attemptService->submitAttempt($attemptId, $studentId),
        ];
    }

    // POST /attempts/{attemptId}/auto-submit
    public function autoSubmit(int $attemptId): array
    {
        $studentId = $this->currentStudentId();

        return [
            'success' => true,
            'message' => 'Attempt auto-submitted due to time limit',
            'data' => $this->attemptService->autoSubmitAttempt($attemptId, $studentId),
        ];
    }

    // GET /attempts/{attemptId}/results
    public function getResults(int $attemptId): array
    {
        $studentId = $this->currentStudentId();

        return [
            'success' => true,
            'message' => 'Results retrieved',
            'data' => $this->attemptService->getResultsForStudent($attemptId, $studentId),
        ];
    }

    // GET /quizzes/{quizId}/my-result
public function getMyLatestResult(int $quizId): array
{
    $studentId = $this->currentStudentId();

    return [
        'success' => true,
        'message' => 'Latest quiz result retrieved',
        'data' => $this->attemptService->getLatestResultForQuiz(
            $quizId,
            $studentId
        ),
    ];
}
} 