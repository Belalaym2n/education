<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Models\QuizStatus;
use App\Services\QuizService;
use App\Validators\QuizValidator;

class QuizController
{
    private QuizService $quizService;

    public function __construct(QuizService $quizService)
    {
        $this->quizService = $quizService;
    }

    // GET /quizzes 
    public function index(): array 
    {
        $filters = [
            'search' => $_GET['search'] ?? null,
            'stage_id' => $_GET['stage_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'has_essay' => isset($_GET['has_essay']) ? filter_var($_GET['has_essay'], FILTER_VALIDATE_BOOLEAN) : null,
            'has_mcq' => isset($_GET['has_mcq']) ? filter_var($_GET['has_mcq'], FILTER_VALIDATE_BOOLEAN) : null,
            'created_by' => $_GET['created_by'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'availability' => $_GET['availability'] ?? null,
        ];
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        $limit = (int) ($_GET['limit'] ?? 20);
        $offset = (int) ($_GET['offset'] ?? 0);
        $sort = $_GET['sort'] ?? 'created_at';
        $order = $_GET['order'] ?? 'desc';

        $result = $this->quizService->listQuizzes($filters, $limit, $offset, $sort, $order);

        return [
            'success' => true,
            'message' => 'Quizzes retrieved successfully',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ];
    }

    // GET /quizzes/insights
    public function insights(): array
    {
        $filters = [
            'search' => $_GET['search'] ?? null,
            'stage_id' => $_GET['stage_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'availability' => $_GET['availability'] ?? null,
            'created_by' => $_GET['created_by'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
        ];
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        return [
            'success' => true,
            'message' => 'Insights calculated successfully',
            'data' => $this->quizService->getInsights($filters),
        ];
    }

    // GET /quizzes/{id}
    public function show(int $id): array
    {
        return [
            'success' => true,
            'message' => 'Quiz retrieved successfully',
            'data' => $this->quizService->getQuiz($id),
        ];
    }

       public function getForStage( ): array
    {
         {
              $userId =
            (int) ($_SERVER['auth_user_id'] ?? 0);
        $filters = [
            'search' => $_GET['search'] ?? null,
            'stage_id' => $_GET['stage_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'has_essay' => isset($_GET['has_essay']) ? filter_var($_GET['has_essay'], FILTER_VALIDATE_BOOLEAN) : null,
            'has_mcq' => isset($_GET['has_mcq']) ? filter_var($_GET['has_mcq'], FILTER_VALIDATE_BOOLEAN) : null,
            'created_by' => $_GET['created_by'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'availability' => $_GET['availability'] ?? null,
        ];
 
        $limit = (int) ($_GET['limit'] ?? 20);
        $offset = (int) ($_GET['offset'] ?? 0);
        $sort = $_GET['sort'] ?? 'created_at';
        $order = $_GET['order'] ?? 'desc';

        $result = $this->quizService->getStageQuizzes( $userId,$filters, $limit, $offset, $sort, $order);

        return [ 
            'success' => true, 
            'message' => 'Quizzes retrieved successfully',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ];
    }}

    // GET /quizzes/{id}/builder  -> quiz + questions, for reopening the question builder
    public function showWithQuestions(int $id): array
    {
        return [
            'success' => true,
            'message' => 'Quiz with questions retrieved successfully',
            'data' => $this->quizService->getQuizWithQuestions($id),
        ];
    }

    // POST /quizzes
    public function store(array $requestData): array
    {
        $validator = $this->validateQuizPayload($requestData);
        $clean = $this->sanitizedQuizFields($validator, $requestData);

        $userId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $quiz = $this->quizService->createQuiz($clean, $userId);

        return ['success' => true, 'message' => 'Quiz created successfully', 'data' => $quiz];
    }

    // PUT /quizzes/{id}
    public function update(int $id, array $requestData): array
    {
        $validator = $this->validateQuizPayload($requestData, true);
        $clean = $this->sanitizedQuizFields($validator, $requestData);

        $userId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $quiz = $this->quizService->updateQuiz($id, $clean, $userId);

        return ['success' => true, 'message' => 'Quiz updated successfully', 'data' => $quiz];
    }

    // PUT /quizzes/{id}/questions  — the question builder's single "Submit" call
    public function saveQuestions(int $id, array $requestData): array
    {
        $validator = new QuizValidator($requestData);
        $validator->validateQuestionsArray('questions');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $force = filter_var($requestData['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $quiz = $this->quizService->saveQuestions($id, $requestData['questions'], $force);

        return ['success' => true, 'message' => 'Questions saved successfully', 'data' => $quiz];
    }

    // PATCH /quizzes/{id}/publish
    public function publish(int $id): array
    {
        return ['success' => true, 'message' => 'Quiz published successfully', 'data' => $this->quizService->publish($id)];
    }

    // PATCH /quizzes/{id}/unpublish
    public function unpublish(int $id): array
    {
        return ['success' => true, 'message' => 'Quiz unpublished successfully', 'data' => $this->quizService->unpublish($id)];
    }

    // PATCH /quizzes/{id}/archive
    public function archive(int $id): array
    {
        return ['success' => true, 'message' => 'Quiz archived successfully', 'data' => $this->quizService->archive($id)];
    }

    // PATCH /quizzes/{id}/visibility  { "is_visible": true|false }
    public function toggleVisibility(int $id, array $requestData): array
    {
        $visible = filter_var($requestData['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN);
        return ['success' => true, 'message' => 'Visibility updated', 'data' => $this->quizService->toggleVisibility($id, $visible)];
    }

    // POST /quizzes/{id}/duplicate
    public function duplicate(int $id): array
    {
        $userId = (int) ($_SERVER['auth_user_id'] ?? 0);
        return ['success' => true, 'message' => 'Quiz duplicated successfully', 'data' => $this->quizService->duplicate($id, $userId)];
    }

    // DELETE /quizzes/{id}
    public function destroy(int $id): array
    {
        $this->quizService->deleteQuiz($id);
        return ['success' => true, 'message' => 'Quiz deleted successfully'];
    }

    private function validateQuizPayload(array $data, bool $isUpdate = false): QuizValidator
    {
        $validator = new QuizValidator($data);

        if (!$isUpdate) {
            $validator->required('stage_id');
            $validator->required('headline');
            $validator->required('start_date');
            $validator->required('end_date');
            $validator->required('duration_seconds');
            $validator->required('passing_score');
        }

        if (isset($data['stage_id'])) {
            $validator->validateNumber('stage_id', 1);
        }
        if (isset($data['passing_score'])) {
            $validator->validateNumber('passing_score', 1);
        }
        if (isset($data['headline'])) {
            $validator->validateString('headline', 3, 200);
        }
        if (isset($data['description'])) {
            $validator->validateString('description', 0, 5000);
        }
        if (isset($data['duration_seconds'])) {
            $validator->validateNumber('duration_seconds', 10);
        }
        if (isset($data['start_date'])) {
            $validator->validateDate('start_date',);
        }
        if (isset($data['end_date'])) {
            $validator->validateDate('end_date');
        }
        $validator->validateTime('start_time');
        $validator->validateTime('end_time');

        if (isset($data['start_date'], $data['end_date'])) {
            $validator->validateScheduleOrder();
        }

        if (isset($data['passing_score'])) {
            $validator->validateNumber('passing_score', 0);
        }
        if (isset($data['max_attempts'])) {
            $validator->validateNumber('max_attempts', 1, 20);
        }

        foreach (['show_result_immediately', 'shuffle_questions', 'shuffle_answers', 'allow_retry', 'negative_marking', 'is_visible'] as $boolField) {
            $validator->validateBoolLike($boolField);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        return $validator;
    }

    private function sanitizedQuizFields(QuizValidator $validator, array $data): array
    {
        $stringFields = array_intersect(['headline', 'description', 'instructions'], array_keys($data));
        $clean = $validator->getSanitized($stringFields);

        $passthroughFields = [
            'stage_id',
            'academic_year_id',
            'start_date',
            'end_date',
            'start_time',
            'end_time',
            'duration_seconds',
            'passing_score',
            'max_attempts',
        ];
        foreach ($passthroughFields as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = $data[$field];
            }
        }

        $boolFields = [
            'show_result_immediately',
            'shuffle_questions',
            'shuffle_answers',
            'allow_retry',
            'negative_marking',
            'is_visible',
        ];
        foreach ($boolFields as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        return $clean;
    }
}
