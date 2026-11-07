<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// The live queue (names, contact numbers, booking reasons) is the head
// nurse's, like the queue endpoints themselves: the dentist and the
// physician see their own bookings, not her line.
Broadcast::channel('queue.{date}.staff', function ($user) {
    return $user->role === 'admin';
});
