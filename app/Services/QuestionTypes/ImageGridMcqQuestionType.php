<?php

namespace App\Services\QuestionTypes;

class ImageGridMcqQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        // Check basic required fields
        if (!isset($questionData['question_text']) || empty($questionData['question_text'])) {
            return false;
        }

        // Check for images array (transformed from image_options)
        if (!isset($questionData['images']) || !is_array($questionData['images']) || empty($questionData['images'])) {
            return false;
        }

        // Validate each image has required fields
        foreach ($questionData['images'] as $image) {
            if (!is_array($image)) {
                return false;
            }
            
            // Must have an ID
            if (!isset($image['id']) || empty($image['id'])) {
                return false;
            }
            
            // Must have either a URL, image_file, or be pending upload
            if (!isset($image['url']) && !isset($image['image_file']) && !isset($image['pending_upload'])) {
                return false;
            }
            
            // Must have is_correct field (boolean)
            if (!isset($image['is_correct']) || !is_bool($image['is_correct'])) {
                return false;
            }
        }

        return true;
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $correctImages = [];
        foreach ($questionData['images'] as $image) {
            if ($image['is_correct'] ?? false) {
                $correctImages[] = $image['id'];
            }
        }

        $selectedImages = $response['selected_images'] ?? [];
        $allowMultiple = $questionData['allow_multiple'] ?? false;

        if ($allowMultiple) {
            // Multiple selection scoring
            $correctlySelected = array_intersect($selectedImages, $correctImages);
            $incorrectlySelected = array_diff($selectedImages, $correctImages);
            $totalCorrect = count($correctImages);

            $score = count($correctlySelected) - (count($incorrectlySelected) * 0.5);
            $score = max(0, $score); // Don't allow negative scores

            return [
                'score' => $score,
                'max_score' => $totalCorrect,
                'percentage' => $totalCorrect > 0 ? round(($score / $totalCorrect) * 100, 2) : 0,
                'is_correct' => count($correctlySelected) === $totalCorrect && count($incorrectlySelected) === 0,
                'feedback' => "Selected " . count($correctlySelected) . " correct and " . count($incorrectlySelected) . " incorrect images.",
                'details' => [
                    'correct_selected' => $correctlySelected,
                    'incorrect_selected' => $incorrectlySelected,
                    'total_correct' => $totalCorrect
                ]
            ];
        } else {
            // Single selection scoring
            $selectedImage = $selectedImages[0] ?? null;
            $isCorrect = in_array($selectedImage, $correctImages);

            return [
                'score' => $isCorrect ? 1 : 0,
                'max_score' => 1,
                'percentage' => $isCorrect ? 100 : 0,
                'is_correct' => $isCorrect,
                'feedback' => $isCorrect ? 'Correct image selected!' : 'Incorrect image selected.',
                'details' => [
                    'selected' => $selectedImage,
                    'correct_images' => $correctImages
                ]
            ];
        }
    }

    public static function renderForStudent(array $questionData): array
    {
        // Handle both formats: new 'images' and legacy 'image_options'
        $images = [];
        
        if (isset($questionData['images']) && is_array($questionData['images'])) {
            // New format with 'images' key
            $images = array_map(function($image) {
                // Prefer image_file over url (url might be placeholder)
                $imageUrl = $image['image_file'] ?? $image['url'] ?? '';
                
                // Skip placeholder URLs
                if (is_string($imageUrl) && (str_starts_with($imageUrl, 'placeholder_') || str_starts_with($imageUrl, 'pending_upload_'))) {
                    $imageUrl = $image['image_file'] ?? '';
                }
                
                return [
                    'id' => $image['id'],
                    'url' => $imageUrl,
                    'image_file' => $image['image_file'] ?? null, // Include for frontend
                    'alt' => $image['alt'] ?? '',
                    'description' => $image['description'] ?? '',
                ];
            }, $questionData['images']);
        } elseif (isset($questionData['image_options']) && is_array($questionData['image_options'])) {
            // Legacy format with 'image_options' key
            $images = array_map(function($option, $index) {
                $imageUrl = $option['image_file'] ?? $option['image_url'] ?? '';
                
                // Skip placeholder URLs
                if (is_string($imageUrl) && (str_starts_with($imageUrl, 'placeholder_') || str_starts_with($imageUrl, 'pending_upload_'))) {
                    $imageUrl = $option['image_file'] ?? '';
                }
                
                return [
                    'id' => 'img_' . ($index + 1),
                    'url' => $imageUrl,
                    'image_file' => $option['image_file'] ?? null,
                    'alt' => $option['label'] ?? "Image " . ($index + 1),
                    'description' => $option['label'] ?? "Image " . ($index + 1),
                ];
            }, $questionData['image_options'], array_keys($questionData['image_options']));
        }

        if ($questionData['shuffle_images'] ?? false) {
            shuffle($images);
        }

        return [
            'question_text' => $questionData['question_text'],
            'images' => $images,
            'allow_multiple' => $questionData['allow_multiple'] ?? false,
            'grid_columns' => $questionData['grid_columns'] ?? 3,
            'instructions' => $questionData['instructions'] ?? 'Select the correct image(s).',
        ];
    }

    public static function renderForAdmin(array $questionData, array $answerSchema): array
    {
        return array_merge($questionData, ['answer_schema' => $answerSchema]);
    }

    public static function getDefaultData(): array
    {
        return [
            'question_text' => '',
            'images' => [
                // Example image structure
                // ['id' => 'img1', 'url' => 'http://example.com/img1.jpg', 'is_correct' => true, 'alt' => 'Image 1', 'description' => 'Description for image 1'],
            ],
            'allow_multiple' => false,
            'shuffle_images' => false,
            'grid_columns' => 3,
            'instructions' => 'Select the correct image(s).',
        ];
    }
    public static function getDefaultAnswerSchema(): array
    {
        return [
            'partial_credit' => true,
            'negative_marking' => true,
            'negative_mark_value' => 0.5,
        ];
    }

    public static function getResponseFormat(): array
    {
        return ['selected_images' => 'array'];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['selected_images']) && is_array($response['selected_images']);
    }
}
