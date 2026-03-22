<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final class EventCatalogue
{
    public const PIPELINE_START           = 'pipeline_start';
    public const STAGE_START              = 'stage_start';
    public const STAGE_COMPLETE           = 'stage_complete';
    public const FILE_CHANGED             = 'file_changed';
    public const CHECKPOINT_WRITTEN       = 'checkpoint_written';
    public const BREAKING_CHANGE_APPLIED  = 'breaking_change_applied';
    public const MANUAL_REVIEW_REQUIRED   = 'manual_review_required';
    public const DEPENDENCY_BLOCKER       = 'dependency_blocker';
    public const VERIFICATION_RESULT      = 'verification_result';
    public const PHPSTAN_REGRESSION       = 'phpstan_regression';
    public const HOP_COMPLETE             = 'hop_complete';
    public const PIPELINE_ERROR           = 'pipeline_error';
    public const WARNING                  = 'warning';
    public const STDERR                   = 'stderr';
    public const HOP_SKIPPED              = 'hop_skipped';
}
