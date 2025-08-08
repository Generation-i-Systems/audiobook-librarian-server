<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class HardcoverTokenExpiring extends Mailable
{
    use Queueable;
    use SerializesModels;

    public $daysUntilExpiration;


    /**
     * Create a new message instance.
     *
     * @param  int  $daysUntilExpiration  Number of days until token expires (0 if expired)
     * @return void
     */
    public function __construct(int $daysUntilExpiration)
    {
        $this->daysUntilExpiration = $daysUntilExpiration;
    }


    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = $this->daysUntilExpiration > 0 ? "Hardcover API Token Expiring in {$this->daysUntilExpiration} " . Str::plural(
            'day',
            $this->daysUntilExpiration
        ) : 'Hardcover API Token Has Expired';

        return $this->subject($subject)->view('emails.hardcover.token-expiring');
    }
}
