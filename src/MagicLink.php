<?php

namespace MagicLink;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use MagicLink\Actions\ActionInterface;
use MagicLink\Events\MagicLinkWasCreated;
use MagicLink\Events\MagicLinkWasVisited;

class MagicLink extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Indicates if Magic link migrations will be run.
     *
     * @var bool
     */
    public static $runsMigrations = true;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->id = preg_match('/5\.5\.*/', App::version())
                ? (string) \Ramsey\Uuid\Uuid::uuid4()
                : (string) Str::uuid();
        });
    }

    public static function ignoreMigrations()
    {
        static::$runsMigrations = false;

        return new static;
    }

    public function getActionAttribute($value)
    {
        if ($this->getConnection()->getDriverName() === 'pgsql') {
            return unserialize(base64_decode($value));
        }

        return unserialize($value);
    }

    public function setActionAttribute($value)
    {
        $this->attributes['action'] = $this->getConnection()->getDriverName() === 'pgsql'
                                        ? base64_encode(serialize($value))
                                        : serialize($value);
    }

    public function getUrlAttribute()
    {
        return url(sprintf(
            '%s/%s:%s',
            config('magiclink.url.validate_path', 'magiclink'),
            $this->id,
            $this->token
        ));
    }

    /**
     * Create makiglink.
     *
     * @param ActionInterface $action
     * @param int|null $lifetime
     * @param int|null $numMaxVisits
     * @return self
     */
    public static function create(ActionInterface $action, ?int $lifetime = 4320, ?int $numMaxVisits = null)
    {
        self::deleteMagicLinkExpired();

        $magiclink = new static();

        $magiclink->token = Str::random(config('magiclink.token.length', 64));

        if ($lifetime) {
            $magiclink->available_at = Carbon::now()->addMinute($lifetime);
        }

        if ($numMaxVisits) {
            $magiclink->max_visits = (int) $numMaxVisits;
        }

        $magiclink->action = $action;

        if (preg_match('/5\.5\.*/', App::version())) {
            $magiclink->id = \Ramsey\Uuid\Uuid::uuid4();
        }

        $magiclink->save();

        Event::dispatch(new MagicLinkWasCreated($magiclink));

        return $magiclink;
    }

    /**
     * Execute Action.
     *
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
     */
    public function run()
    {
        return $this->action->run();
    }

    /**
     * Call when magiclink has been visited.
     *
     * @return void
     */
    public function visited()
    {
        try {
            $this->increment('num_visits');
        } catch (QueryException $e) {
        }

        Event::dispatch(new MagicLinkWasVisited($this));
    }

    /**
     * Get valid MagicLink by token.
     *
     * @param string $token
     * @return null|\MagicLink\MagicLink
     */
    public static function getValidMagicLinkByToken($token)
    {
        [$tokenId, $tokenSecret] = explode(':', "{$token}:");

        if (empty($tokenSecret)) {
            return;
        }

        return self::where('id', $tokenId)
                    ->where('token', $tokenSecret)
                    ->where('available_at', '>=', Carbon::now())
                    ->where(function ($query) {
                        $query
                            ->whereNull('max_visits')
                            ->orWhereRaw('max_visits > num_visits');
                    })
                    ->first();
    }

    /**
     * Get MagicLink by token.
     *
     * @param string $token
     * @return null|\MagicLink\MagicLink
     */
    public static function getMagicLinkByToken($token)
    {
        [$tokenId, $tokenSecret] = explode(':', "{$token}:");

        if (empty($tokenSecret)) {
            return;
        }

        return self::where('id', $tokenId)
                    ->where('token', $tokenSecret)
                    ->first();
    }

    /**
     * Delete magiclink was expired.
     *
     * @return void
     */
    public static function deleteMagicLinkExpired()
    {
        self::where(function ($query) {
            $query
                ->where('available_at', '<', Carbon::now())
                ->orWhere(function ($query) {
                    $query
                        ->whereNotNull('max_visits')
                        ->whereRaw('max_visits <= num_visits');
                });
        })
        ->delete();
    }

    /**
     * Delete all MagicLink.
     *
     * @return void
     */
    public static function deleteAllMagicLink()
    {
        self::truncate();
    }
}
