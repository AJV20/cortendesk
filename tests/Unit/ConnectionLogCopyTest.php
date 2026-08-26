<?php

namespace Tests\Unit;

use App\Models\AuditConnection;
use PHPUnit\Framework\TestCase;

final class ConnectionLogCopyTest extends TestCase
{
    public function test_it_uses_clear_wording_for_remote_desktop_connections(): void
    {
        self::assertSame('Remote Desktop', AuditConnection::typeLabel(0));
        self::assertSame('Remote Device', AuditConnection::REMOTE_DEVICE_LABEL);
        self::assertSame('Other', AuditConnection::typeLabel(99));
    }

    public function test_active_sessions_uses_the_shared_type_labels_in_both_layouts(): void
    {
        $view = file_get_contents(__DIR__.'/../../resources/views/livewire/active-sessions.blade.php');

        self::assertSame(2, substr_count($view, 'AuditConnection::typeLabel((int) $s->conn_type)'));
        self::assertStringNotContainsString('>Remote</span>', $view);
    }
}
