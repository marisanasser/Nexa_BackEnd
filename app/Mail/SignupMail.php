<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SignupMail extends Mailable
{
    public $user;

    public $link;

    public function __construct($user, $link)
    {
        $this->user = $user;
        $this->link = $link;
    }

    public function build()
    {
        $html = "
            <div style='font-family:Arial,sans-serif; color:#111;'>
              <h2>Welcome, {$this->user->name}</h2>
              <p>Welcome for signing up to <strong>Nexa</strong>.</p>
              <p><a href=\"{$this->link}\" style=\"display:inline-block;padding:10px 18px;border-radius:6px;text-decoration:none;background:#2563eb;color:#fff;\">Sign In</a></p>
              <p style='font-size:12px;color:#666'>If the button doesn't work copy this link:<br>{$this->link}</p>
              <p style='font-size:12px;color:#999'>This link expires in 30 minutes.</p>
            </div>
        ";

        return $this->subject('Sign in Nexa')
            ->html($html)
        ;
    }
}
