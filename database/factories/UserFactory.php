<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Test/factory accounts represent normal staff, not pending
            // self-registrations — default to already approved so the rest
            // of the suite doesn't trip EnsureAccountApproved. Use
            // ->pendingApproval() to opt into the unapproved state.
            'approved_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate a self-registered account nobody has approved yet — the
     * state EnsureAccountApproved actually gates on.
     */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
        ]);
    }
}
