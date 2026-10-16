<?php

namespace App\Enums;

/**
 * The lifecycle of a share, as recorded for its owner (§50).
 *
 * Split between things the *owner* did (created, revoked, regenerated,
 * password changed, permissions changed) and things a *recipient* did
 * (viewed, downloaded, failed the password). The owner needs both: the
 * first to remember what they set up, the second to notice when a link
 * has travelled further than intended.
 */
enum ShareEvent: string
{
    case Created = 'created';
    case Revoked = 'revoked';
    case Regenerated = 'regenerated';
    case PasswordChanged = 'password_changed';
    case PermissionsChanged = 'permissions_changed';

    case Viewed = 'viewed';
    case PdfDownloaded = 'pdf_downloaded';
    case PasswordFailed = 'password_failed';
    case LockedOut = 'locked_out';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Share link created',
            self::Revoked => 'Share link revoked',
            self::Regenerated => 'Share link regenerated',
            self::PasswordChanged => 'Password changed',
            self::PermissionsChanged => 'Permissions changed',
            self::Viewed => 'Report opened',
            self::PdfDownloaded => 'PDF downloaded',
            self::PasswordFailed => 'Incorrect password',
            self::LockedOut => 'Locked out after repeated failures',
        };
    }

    /**
     * Whether this event was caused by a recipient rather than the owner.
     * The owner's own actions are not interesting in a "who has been
     * opening my link" view.
     */
    public function isRecipientAction(): bool
    {
        return in_array($this, [self::Viewed, self::PdfDownloaded, self::PasswordFailed, self::LockedOut], true);
    }
}
