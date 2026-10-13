<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;

/**
 * A policy that says yes to everything.
 *
 * LibreNMS ships real policies and the Gate looks a class up by convention
 * before it falls back to an ability defined with Gate::define, so defining
 * 'viewAll' in a test does nothing at all. The pages ask the Gate whether the
 * user may see every device, and this is what answers yes without dragging
 * LibreNMS's whole permission apparatus into the fixture.
 *
 * A real class rather than a closure: the Gate needs a class name, and any
 * ability it does not recognise is then denied by default, so the methods it
 * does call cover what the pages ask for.
 */
class AlwaysAllowsPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, mixed $model = null): bool
    {
        return true;
    }

    public function viewAll(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, mixed $model = null): bool
    {
        return true;
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return true;
    }
}
