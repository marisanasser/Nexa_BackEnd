<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = \App\Models\User\User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => fake()->randomElement(['creator', 'brand']),
            'whatsapp' => fake()->optional()->numerify('+############'),
            'avatar_url' => function (array $attributes) {
                return 'https://ui-avatars.com/api/?name=' . urlencode($attributes['name']) . '&color=7F9CF5&background=EBF4FF';
            },
            'bio' => fake()->optional()->paragraph(3),
            'company_name' => fake()->optional()->company(),
            'student_verified' => false,
            'student_expires_at' => null,
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'state' => fake()->optional()->state(),
            'languages' => [fake()->randomElement(['en', 'es', 'fr', 'de'])],
            'has_premium' => false,
            'premium_expires_at' => null,
            'free_trial_expires_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_premium' => true,
            'premium_expires_at' => now()->addYear(),
        ]);
    }

    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_premium' => false,
            'free_trial_expires_at' => now()->addDays(30),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    public function studentVerified(): static
    {
        return $this->state(fn (array $attributes) => [
            'student_verified' => true,
            'student_expires_at' => now()->addYear(),
        ]);
    }
}
