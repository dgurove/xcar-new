<?php

namespace App\Park;

enum EventType: string
{
    case Created = 'created';
    case Accepted = 'accepted';
    case Moved = 'moved';
    case Inspected = 'inspected';
    case Towed = 'towed';
    case Released = 'released';
    case Note = 'note';
    case Updated = 'updated';
    case DocSent = 'doc_sent';
    case DocBack = 'doc_back';
    case Scheduled = 'scheduled';
    case Departed = 'departed';
    case Assigned = 'assigned';
    case Cancelled = 'cancelled';
    case Linked = 'linked';
    case Charged = 'charged';
    case Invoiced = 'invoiced';
    case Owed = 'owed';
    case Paid = 'paid';
    case InvoiceVoided = 'invoice_voided';
    case Letter = 'letter';
    case LetterAnswered = 'letter_answered';
    case Called = 'called';
    case Sold = 'sold';
    case ReleaseRefused = 'release_refused';
    case ReportSent = 'report_sent';
    case Restored = 'restored';
    case IntakeUndone = 'intake_undone';
    case ReleaseUndone = 'release_undone';
    case PickupLinkSent = 'pickup_link_sent';
    case PickupLinkRenewed = 'pickup_link_renewed';
    case BuyerForm = 'buyer_form';
    case BuyerConfirmed = 'buyer_confirmed';
    case BuyerRejected = 'buyer_rejected';
}
