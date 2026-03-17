<?php

namespace App\Services\AI\BackgroundAgents;

use App\Services\AI\BackgroundAgents\Agents\AssessmentFeedbackAgent;
use App\Services\AI\BackgroundAgents\Agents\CustomerSupportAgent;
use App\Services\AI\BackgroundAgents\Agents\DataQualityAgent;
use App\Services\AI\BackgroundAgents\Agents\GrowthSalesAgent;
use App\Services\AI\BackgroundAgents\Agents\ParentManagerAgent;
use App\Services\AI\BackgroundAgents\Agents\PaymentCollectorAgent;

class BackgroundAgentRegistry
{
    protected static array $agents = [
        'data_quality' => DataQualityAgent::class,
        'assessment_feedback' => AssessmentFeedbackAgent::class,
        'parent_manager' => ParentManagerAgent::class,
        'payment_collector' => PaymentCollectorAgent::class,
        'growth_sales' => GrowthSalesAgent::class,
        'customer_support' => CustomerSupportAgent::class,
    ];

    /**
     * Get all registered agent types with their class names.
     */
    public static function all(): array
    {
        return static::$agents;
    }

    /**
     * Get agent class by type.
     */
    public static function get(string $type): ?string
    {
        return static::$agents[$type] ?? null;
    }

    /**
     * Register a new agent type at runtime.
     */
    public static function register(string $type, string $class): void
    {
        static::$agents[$type] = $class;
    }

    /**
     * Get metadata for all agents (for dashboard display).
     */
    public static function allWithMeta(): array
    {
        $result = [];
        foreach (static::$agents as $type => $class) {
            $result[$type] = [
                'type' => $type,
                'class' => $class,
                'description' => $class::getDescription(),
                'default_schedule' => $class::getDefaultSchedule(),
                'estimated_tokens_per_run' => $class::getEstimatedTokensPerRun(),
                'event_triggers' => $class::getEventTriggers(),
                'is_stub' => method_exists($class, 'isStub') && $class::isStub(),
            ];
        }
        return $result;
    }

    /**
     * Get agents that have scheduled triggers.
     */
    public static function scheduledAgents(): array
    {
        return array_filter(static::$agents, function ($class) {
            return $class::getDefaultSchedule() !== '';
        });
    }

    /**
     * Get agents that respond to a specific event.
     */
    public static function eventDrivenAgents(string $eventClass): array
    {
        return array_filter(static::$agents, function ($class) use ($eventClass) {
            return in_array($eventClass, $class::getEventTriggers());
        });
    }
}
