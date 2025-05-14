<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

    class User extends Authenticatable
    {
        use HasApiTokens, HasFactory, Notifiable;

        /**
         * The attributes that are mass assignable.
         *
         * @var array<int, string>
         */
        protected $fillable = [
            'name',
            'username',
            'email',
            'password',
            'role',
            'device_token',
            'download_id',
        ];

        /**
         * The attributes that should be hidden for serialization.
         *
         * @var array<int, string>
         */
        protected $hidden = [
            'password',
            'remember_token',
        ];

        /**
         * The attributes that should be cast.
         *
         * @var array<string, string>
         */
        protected $casts = [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];

        protected static function booted()
        {
            static::creating(function ($user) {
                if (empty($user->download_id)) {
                    $user->download_id = (string) \Illuminate\Support\Str::uuid();
                }
            });
            static::updating(function ($user) {
                if (empty($user->download_id)) {
                    $user->download_id = (string) \Illuminate\Support\Str::uuid();
                }
            });
        }

        public function reviews()
        {
            return $this->hasMany(Review::class);
        }

        public function queues()
        {
            return $this->hasMany(BookQueue::class);
        }

        public function bookRequests()
        {
            return $this->hasMany(BookRequest::class);
        }

        public function follows()
        {
            return $this->hasMany(Follow::class);
        }

        public function readingProgresses()
        {
            return $this->hasMany(ReadingProgress::class);
        }

        public function messages()
        {
            return $this->hasMany(Message::class);
        }
    }
