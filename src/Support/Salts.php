<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\Salt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The daily salt of visitor hashes. A salt lives until the end of its UTC day; the next hit after that
 * creates a new one and deletes every older salt, so a visitor hash can never be
 * recomputed (or matched to the same person) once its day is over.
 */
final class Salts
{
    public const LIFETIME_HOURS = 24;

    /**
     * The salt of the current 24 hours, rotated when it is due.
     */
    public static function current(): string
    {
        $salt = Salt::query()->latest('id')->first();

        if ($salt === null || self::isExpired($salt)) {
            $salt = self::rotate();
        }

        return $salt->salt;
    }

    /**
     * A salt is used for the UTC day it was created on (at most 24 hours), so every visitor
     * gets one id per day.
     */
    public static function isExpired(Salt $salt): bool
    {
        return $salt->created_at->lt(Carbon::now('UTC')->startOfDay()) || $salt->created_at->lte(Carbon::now()->subHours(self::LIFETIME_HOURS));
    }

    /**
     * Create a new salt and delete the older ones.
     */
    public static function rotate(): Salt
    {
        $rotate = static function (): Salt {
            $latest = Salt::query()->latest('id')->first();

            // Another request rotated while this one waited for the lock.
            if ($latest !== null && ! self::isExpired($latest)) {
                return $latest;
            }

            $salt = new Salt;
            $salt->forceFill(['salt' => bin2hex(random_bytes(32)), 'created_at' => Carbon::now()]);
            $salt->save();

            Salt::query()->whereKeyNot($salt->getKey())->delete();

            return $salt;
        };

        try {
            return Cache::lock('alfakit-analytics:salt-rotation', 10)->block(5, $rotate);
        } catch (Throwable) {
            return $rotate();
        }
    }

    /**
     * Rotate when the current salt is older than 24 hours (the scheduled command calls it so
     * old salts are deleted even on days without traffic).
     */
    public static function rotateIfDue(): bool
    {
        $salt = Salt::query()->latest('id')->first();

        if ($salt !== null && ! self::isExpired($salt)) {
            Salt::query()->whereKeyNot($salt->getKey())->delete();

            return false;
        }

        self::rotate();

        return true;
    }
}
