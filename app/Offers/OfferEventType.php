<?php

namespace App\Offers;

enum OfferEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StateChanged = 'state_changed';
    case BidPlaced = 'bid_placed';
    case BidWithdrawn = 'bid_withdrawn';
    case BidAccepted = 'bid_accepted';
    case BidDeclined = 'bid_declined';
    case Interest = 'interest';
    case Note = 'note';
    case StageEntered = 'stage_entered';
    case StageReminded = 'stage_reminded';
    case StageOverdue = 'stage_overdue';
    case PlaceChanged = 'place_changed';
    case RequirementAnswered = 'requirement_answered';
    case RouteDropped = 'route_dropped';
}
