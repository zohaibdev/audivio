<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\ApiProvider;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds exactly one AiModel catalog row per AI provider: whichever model
 * that provider is currently actively configured to call
 * (ApiProvider::$model). This is the minimum needed for
 * PlanAiModelResolver to find a usable match out of the box — additional
 * models are an admin-curated addition via the AI Models admin screen, not
 * invented here with guessed cost/context-window numbers. Every existing
 * plan is granted access to whatever gets seeded here, same
 * backward-compatibility reasoning as FeatureSeeder: AI analysis ran
 * unconditionally for every plan before this registry existed.
 */
class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ApiProvider::where('provider_type', 'ai')->get() as $provider) {
            if ($provider->model === null) {
                continue;
            }

            $model = AiModel::updateOrCreate(
                ['api_provider_id' => $provider->id, 'model_id' => $provider->model],
                [
                    'display_name' => $provider->model,
                    'is_enabled' => true,
                ],
            );

            foreach (Plan::all() as $plan) {
                $plan->allowedAiModels()->syncWithoutDetaching([$model->id]);
            }
        }
    }
}
