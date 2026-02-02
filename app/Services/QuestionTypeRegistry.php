<?php

namespace App\Services;

use App\Services\QuestionTypes\McqQuestionType;
use App\Services\QuestionTypes\ClozeQuestionType;
use App\Services\QuestionTypes\ShortAnswerQuestionType;
use App\Services\QuestionTypes\LongAnswerQuestionType;
use App\Services\QuestionTypes\OrderingQuestionType;
use App\Services\QuestionTypes\MatchingQuestionType;
use App\Services\QuestionTypes\ImageGridMcqQuestionType;
use App\Services\QuestionTypes\ComprehensionQuestionType;

class QuestionTypeRegistry
{
    protected static array $types = [
        'mcq' => McqQuestionType::class,
        'cloze' => ClozeQuestionType::class,
        'short_answer' => ShortAnswerQuestionType::class,
        'long_answer' => LongAnswerQuestionType::class,
        'ordering' => OrderingQuestionType::class,
        'matching' => MatchingQuestionType::class,
        'image_grid_mcq' => ImageGridMcqQuestionType::class,
        'comprehension' => ComprehensionQuestionType::class,
    ];

    protected static array $typeDefinitions = [
        'mcq' => [
            'name' => 'Multiple Choice Question',
            'description' => 'Traditional multiple choice with single or multiple correct answers',
            'icon' => '📋',
            'category' => 'basic',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'automatic',
            'example' => 'What is the capital of France? A) London B) Paris C) Berlin'
        ],
        'cloze' => [
            'name' => 'Cloze/Gap Fill',
            'description' => 'Fill in the blanks in a passage of text',
            'icon' => '📝',
            'category' => 'basic',
            'supports_images' => false,
            'supports_media' => false,
            'grading' => 'automatic',
            'example' => 'The capital of France is {blank} and it has a population of {blank}.'
        ],
        'short_answer' => [
            'name' => 'Short Answer',
            'description' => 'Open-ended short text response requiring AI or manual grading',
            'icon' => '✍️',
            'category' => 'open',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'ai_assisted',
            'example' => 'Explain the process of photosynthesis in 2-3 sentences.'
        ],
        'ordering' => [
            'name' => 'Ordering/Sequencing',
            'description' => 'Arrange items in the correct order',
            'icon' => '🔢',
            'category' => 'interactive',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'automatic',
            'example' => 'Arrange the steps of the water cycle in order.'
        ],
        'matching' => [
            'name' => 'Matching',
            'description' => 'Match terms with their definitions or pairs',
            'icon' => '🔗',
            'category' => 'interactive',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'automatic',
            'example' => 'Match each country with its capital city.'
        ],
        'image_grid_mcq' => [
            'name' => 'Image Grid MCQ',
            'description' => 'Multiple choice using images instead of text options',
            'icon' => '🖼️',
            'category' => 'visual',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'automatic',
            'example' => 'Select all images that show mammals.'
        ],
        'long_answer' => [
            'name' => 'Long Answer/Essay',
            'description' => 'Extended essay responses with rich text formatting (no word limit)',
            'icon' => '📝',
            'category' => 'open',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'manual',
            'example' => 'Analyze the causes and effects of climate change in essay format.'
        ],
        'comprehension' => [
            'name' => 'Reading Comprehension',
            'description' => 'Passage-based questions with multiple sub-questions',
            'icon' => '📖',
            'category' => 'complex',
            'supports_images' => true,
            'supports_media' => false,
            'grading' => 'automatic',
            'example' => 'Read the passage about ancient civilizations and answer the questions.'
        ],
    ];

    public static function register(string $type, string $class, array $definition = []): void
    {
        static::$types[$type] = $class;
        
        if (!empty($definition)) {
            static::$typeDefinitions[$type] = $definition;
        }
    }

    public static function getHandler(string $type): ?string
    {
        return static::$types[$type] ?? null;
    }

    public static function getAllTypes(): array
    {
        return static::$types;
    }

    public static function getTypeDefinition(string $type): ?array
    {
        return static::$typeDefinitions[$type] ?? null;
    }

    public static function getAllTypeDefinitions(): array
    {
        return static::$typeDefinitions;
    }

    public static function getAvailableTypes(): array
    {
        $available = [];
        
        foreach (static::$typeDefinitions as $type => $definition) {
            $available[$type] = [
                'id' => $type,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'category' => $definition['category'],
                'supports_images' => $definition['supports_images'],
                'supports_media' => $definition['supports_media'],
                'grading' => $definition['grading'],
                'example' => $definition['example'],
                'handler_exists' => class_exists(static::$types[$type] ?? ''),
            ];
        }
        
        return $available;
    }

    public static function getTypesByCategory(string $category): array
    {
        return array_filter(static::getAvailableTypes(), function($type) use ($category) {
            return $type['category'] === $category;
        });
    }

    public static function isValidType(string $type): bool
    {
        return array_key_exists($type, static::$types);
    }

    public static function getDefaultQuestionData(string $type): array
    {
        $handler = static::getHandler($type);
        
        if ($handler && method_exists($handler, 'getDefaultData')) {
            return $handler::getDefaultData();
        }
        
        return [];
    }

    public static function getDefaultAnswerSchema(string $type): array
    {
        $handler = static::getHandler($type);
        
        if ($handler && method_exists($handler, 'getDefaultAnswerSchema')) {
            return $handler::getDefaultAnswerSchema();
        }
        
        return [];
    }
}
