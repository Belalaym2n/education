<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\AttemptService;

class AttemptController
{
    private AttemptService $attemptService;

    public function __construct(AttemptService $attemptService)
    {
        $this->attemptService = $attemptService;
    }

    // POST /quizzes/{quizId}/attempts  — start or resume an attempt
    public function start(int $quizId): array
    {
        $studentId = (int) ($_SERVER['auth_studetn_id'] ?? 0);


        return [
            'success' => true,
            'message' => 'Attempt started',
            'data' => $this->attemptService->startAttempt($quizId, $studentId),
        ];
    }

    // GET /attempts/{attemptId}  — get current state of an attempt (resume)
    public function show(int $attemptId): array
    {
        return [
            'success' => true,
            'message' => 'Attempt loaded',
            'data' => $this->attemptService->getAttempt($attemptId),
        ];
    }

    // PUT /attempts/{attemptId}/answers/{questionId}  — save an answer (auto-save)
    public function saveAnswer(int $attemptId, int $questionId, array $requestData): array
    {
        $validator = new \App\Validators\Validator($requestData);


        // At least one field must be set.
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

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        return [
            'success' => true,
            'message' => 'Answer saved',
            'data' => $this->attemptService->saveAnswer($attemptId, $questionId, $requestData),
        ];
    }

    // POST /attempts/{attemptId}/submit  — submit the entire attempt
    public function submit(int $attemptId): array
    {
        return [
            'success' => true,
            'message' => 'Attempt submitted successfully',
            'data' => $this->attemptService->submitAttempt($attemptId),
        ];
    }

    // POST /attempts/{attemptId}/auto-submit  — called when timer expires
    public function autoSubmit(int $attemptId): array
    {
        return [
            'success' => true,
            'message' => 'Attempt auto-submitted due to time limit',
            'data' => $this->attemptService->autoSubmitAttempt($attemptId),
        ];
    }

    // GET /attempts/{attemptId}/results  — get results (only if visible to student)
    public function getResults(int $attemptId): array
    {
        $studentId = (int) ($_SERVER['auth_student_id'] ?? 0);

        echo $studentId;
        die;
        return [
            'success' => true,
            'message' => 'Results retrieved',
            'data' => $this->attemptService->getResultsForStudent($attemptId, $studentId),
        ];
    }
}
 