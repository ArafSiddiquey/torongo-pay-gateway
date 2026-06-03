<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAppsDownloadTest extends TestCase
{
    public function test_admin_apps_page_lists_downloads(): void
    {
        $this->withSession(['admin_id' => 1])
            ->get(route('admin.apps'))
            ->assertOk()
            ->assertSee('Apps & Plugins', false)
            ->assertSee('Torongo Verify Android App')
            ->assertSee('Torongo Pay WordPress Plugin')
            ->assertSee('Download');
    }

    public function test_admin_can_download_packaged_artifacts(): void
    {
        foreach (['android-app', 'wordpress-plugin'] as $artifact) {
            $this->withSession(['admin_id' => 1])
                ->get(route('admin.apps.download', $artifact))
                ->assertOk();
        }
    }

    public function test_downloads_require_admin_session(): void
    {
        $this->get(route('admin.apps'))
            ->assertRedirect(route('admin.login'));
    }
}
