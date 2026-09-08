<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Legacy `users` table (D2: verbatim schema, zero migrations).
 *
 * @property int $id
 * @property string|null $user_name
 * @property string|null $password
 * @property string|null $userLevel
 * @property string|null $userQuestion
 * @property string|null $userQuestionAnswer
 * @property string|null $userCreated
 * @property string|null $userToken
 * @property string|null $userTokenTime
 * @property int|null $userFailedLogins
 * @property string|null $userFailedLoginTime
 * @property int|null $userAdminObfuscate
 *
 * The legacy table has no `email`/`remember_token`/`email_verified_at`
 * columns: credentials are `user_name` (email address), passwords are
 * bcrypt (`$2y$`, or legacy `$2a$` over md5(plaintext)), and `userLevel`
 * is a char: '1' admin / '2' entrant / '3' participant.
 *
 * Login by `user_name` is achieved through the credentials array passed to
 * `Auth::attempt(['user_name' => ..., 'password' => ...])` —
 * `retrieveByCredentials()` builds the WHERE from the credential keys.
 * `getAuthIdentifierName()` stays `id` (the PK) so `retrieveById()`
 * (loginUsingId, session guards) queries the right column.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id';

    /** There is no auto-incrementing `id` guarantee we rely on elsewhere; keep timestamps off — legacy rows have no created/updated columns. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_name',
        'password',
        'userLevel',
        'userQuestion',
        'userQuestionAnswer',
        'userCreated',
        'userToken',
        'userTokenTime',
        'userFailedLogins',
        'userFailedLoginTime',
        'userAdminObfuscate',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'userQuestionAnswer',
        'userToken',
        'userTokenTime',
    ];

    public function isAdmin(): bool
    {
        return (int) $this->userLevel <= 1;
    }

    public function isEntrant(): bool
    {
        return (int) $this->userLevel === 2;
    }

    public function isParticipant(): bool
    {
        return (int) $this->userLevel === 3;
    }
}
