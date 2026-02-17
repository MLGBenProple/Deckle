<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User model for authentication and account management.
 * 
 * This model extends Laravel's default Authenticatable user class to provide
 * authentication services for the Deckle tournament puzzle application.
 * Currently implements basic user authentication without requiring email
 * verification, suitable for a puzzle game that doesn't need complex
 * user management features.
 * 
 * The model supports:
 * - Standard Laravel authentication (login/logout/registration)
 * - Password hashing and security
 * - Remember token functionality for "stay logged in" features
 * - Factory-based testing and seeding
 * - Laravel notification system integration
 * 
 * Future enhancements might include:
 * - User game statistics and progress tracking
 * - Tournament performance history
 * - Social features (leaderboards, sharing)
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     * 
     * Defines which user attributes can be safely assigned during bulk
     * operations like registration or profile updates. This protection
     * prevents mass assignment vulnerabilities while allowing controlled
     * user data management.
     * 
     * - name: User's display name for game attribution
     * - email: Unique email address for authentication
     * - password: Will be automatically hashed via the casts() method
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * 
     * These sensitive attributes are automatically excluded when the User
     * model is converted to JSON (for API responses, frontend data passing).
     * This prevents accidental exposure of authentication secrets.
     * 
     * - password: Hashed password should never be transmitted to client
     * - remember_token: Session security token for "remember me" functionality
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Configure automatic casting and transformation for model attributes.
     * 
     * These casts ensure proper data handling for specific attribute types:
     * - email_verified_at: Automatically converts to Carbon datetime instance
     *   for easy date manipulation (if email verification is enabled)
     * - password: Automatically hashes plaintext passwords using Laravel's
     *   secure hashing algorithm (bcrypt/Argon2)
     * 
     * The password hashing cast ensures that plaintext passwords are never
     * stored in the database, even if accidentally passed during assignment.
     *
     * @return array<string, string> Casting configuration
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
