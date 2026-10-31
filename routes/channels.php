<?php

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('users.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id || $user->isAdmin();
});

Broadcast::channel('lines.{phoneNumberId}', function (User $user, $phoneNumberId) {
    if ($user->isAdmin()) {
        return true;
    }

    return $user->phoneNumbers()
        ->where('phone_numbers.id', (int) $phoneNumberId)
        ->exists();
});

Broadcast::channel('admin.messages', function (User $user) {
    return $user->isAdmin();
});
