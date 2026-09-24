<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DeploymentWorkflowContractTest extends TestCase
{
    public function test_workflow_builds_and_pushes_one_unified_sae_image_for_amd64(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString('actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803 # v6', $workflow);
        self::assertStringContainsString('shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2', $workflow);
        self::assertStringContainsString('actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38 # v6', $workflow);
        self::assertStringContainsString('docker/setup-buildx-action@f87e5991a6d7451dcb8d9637bfbc97413f497069 # v4', $workflow);
        self::assertStringContainsString('docker/login-action@dbcb813823bdd20940b903addbd779551569679f # v4', $workflow);
        self::assertStringContainsString('docker/build-push-action@c3c9e263c25d99ce0380d002d59b67737d91b0dc # v7', $workflow);
        self::assertStringContainsString('file: docker/Dockerfile.prod', $workflow);
        self::assertStringContainsString('target: sae-all', $workflow);
        self::assertStringContainsString('platforms: linux/amd64', $workflow);
        self::assertMatchesRegularExpression('/provenance:\s*false/', $workflow);
        self::assertMatchesRegularExpression('/sbom:\s*false/', $workflow);
        self::assertStringContainsString(':${{ github.sha }}', $workflow);
        self::assertStringContainsString('ACR_LOGIN_REGISTRY }}/${{ env.ACR_NAMESPACE }}/${{ env.ACR_REPOSITORY }}:${{ github.sha }}', $workflow);
        self::assertStringNotContainsString('Dockerfile.sae-web', $workflow);
        self::assertStringNotContainsString('${{ github.sha }}-web', $workflow);
    }

    public function test_workflow_supports_main_push_and_manual_unified_app_selection(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString("push:\n    branches:\n      - main", $workflow);
        self::assertStringContainsString('workflow_dispatch:', $workflow);

        self::assertStringContainsString('      deploy_app:', $workflow);
        self::assertStringContainsString('        description: Deploy the unified GEOFlow SAE application', $workflow);
        self::assertStringContainsString('        default: true', $workflow);
        self::assertStringContainsString(
            "if: \${{ needs.build.result == 'success' && (github.event_name == 'push' || inputs.deploy_app == true) }}",
            $workflow,
        );
        self::assertStringNotContainsString('deploy_web:', $workflow);
        self::assertStringNotContainsString('deploy_worker:', $workflow);
        self::assertStringNotContainsString('run_release:', $workflow);
    }

    public function test_workflow_builds_frontend_assets_before_running_php_tests(): void
    {
        $workflow = $this->workflow();

        self::assertMatchesRegularExpression(
            '/- name: Install JavaScript dependencies\s+run: npm ci.*?- name: Build production assets\s+run: npm run build.*?- name: Run PHP test suite\s+run: composer test/s',
            $workflow,
        );
    }

    public function test_workflow_uses_configurable_acr_and_aliyun_credentials(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'vars.ACR_LOGIN_REGISTRY',
            'vars.ACR_IMAGE_REGISTRY',
            'vars.ACR_NAMESPACE',
            'vars.ACR_REPOSITORY',
            'vars.SAE_REGION_ID',
            'secrets.ACR_USERNAME',
            'secrets.ACR_PASSWORD',
            'secrets.ALIYUN_SAE_AK_ID',
            'secrets.ALIYUN_SAE_AK_SECRET',
            'secrets.SAE_APP_ID',
        ] as $configuration) {
            self::assertStringContainsString($configuration, $workflow);
        }

        self::assertStringContainsString('https://github.com/aliyun/aliyun-cli/releases/download/v3.5.1/aliyun-cli-linux-3.5.1-amd64.tgz', $workflow);
        self::assertStringContainsString('sha256sum --check --status', $workflow);
        self::assertStringContainsString('aliyun sae DeployApplication', $workflow);
        self::assertStringContainsString('--ImageUrl "$app_image"', $workflow);
        self::assertStringContainsString('app_image=', $workflow);
        self::assertStringNotContainsString('DescribeApplicationStatus', $workflow);
        self::assertStringNotContainsString('web_image=', $workflow);
    }

    public function test_workflow_deploys_exactly_one_sae_application(): void
    {
        $workflow = $this->workflow();

        self::assertSame(1, substr_count($workflow, 'secrets.SAE_APP_ID'));
        self::assertStringContainsString('if [[ -z "$SAE_APP_ID" ]]; then', $workflow);
        self::assertStringContainsString('--AppId "$SAE_APP_ID"', $workflow);

        foreach ([
            'SAE_WEB_APP_ID',
            'SAE_WORKER_APP_ID',
            'SAE_AI_QUALITY_FRONT_APP_ID',
            'SAE_AI_QUALITY_BACKFILL_APP_ID',
            'SAE_AI_OPTIMIZATION_APP_ID',
            'SAE_KNOWLEDGE_APP_ID',
            'SAE_SCHEDULER_APP_ID',
            'SAE_REVERB_APP_ID',
            'SAE_RELEASE_APP_ID',
        ] as $legacySecret) {
            self::assertStringNotContainsString($legacySecret, $workflow);
        }
    }

    public function test_workflow_never_runs_database_installation_or_migration(): void
    {
        $workflow = $this->workflow();

        self::assertStringNotContainsString('artisan migrate', $workflow);
        self::assertStringNotContainsString('geoflow:install', $workflow);
        self::assertStringNotContainsString('migrate --force', $workflow);
    }

    public function test_release_actions_are_owned_by_the_unified_container(): void
    {
        $workflow = $this->workflow();

        self::assertStringNotContainsString('jobs:\n  release:', $workflow);
        self::assertStringNotContainsString('SAE_RELEASE_APP_ID', $workflow);
        self::assertStringContainsString('target: sae-all', $workflow);
        self::assertStringContainsString('needs: build', $workflow);
    }

    private function workflow(): string
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy-sae.yml');

        self::assertIsString($workflow);

        return $workflow;
    }
}
