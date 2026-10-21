<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SessionRevocationService
{
    public function revokeDatabaseSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = config('session.table', 'sessions');
        if (is_string($table) && Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $user->getKey())->delete();
        }
    }
}
