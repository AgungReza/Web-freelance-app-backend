<?php

namespace App\Policies;

use App\Models\ClientExpense;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ClientExpensePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id;
    }

    public function create(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id;
    }

    public function delete(User $user, ClientExpense $expense): bool
    {
        return $user->id === $expense->user_id;
    }
}