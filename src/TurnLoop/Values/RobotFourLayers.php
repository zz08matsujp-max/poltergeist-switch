<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Values;

use PoltergeistSwitch\Engine\Values\Judgement;
use PoltergeistSwitch\Engine\Values\Outcome;

/**
 * The Four_Layers for one robot on one turn, as carried in an EpisodeView
 * (brief §2, §9; Requirements 6.4, 6.8, 9.4, 10.2).
 *
 * The four layers are: the situation text the robot was sent, the robot's
 * reply, the recorder's reading, and the rules' judgement plus the outcome.
 * A robot marked no reply (Requirement 5.6) has an empty reply, noReply = true,
 * and a reading whose action operation is 'unknown', while still carrying its
 * judgement and outcome (Requirements 6.8, 10.3).
 */
readonly class RobotFourLayers
{
    public function __construct(
        public string $situationText,     // layer 1: the text the robot was sent
        public string $reply,             // layer 2: the robot's reply ('' when no reply)
        public bool $noReply,             // true when the robot was marked no reply (Req 5.6, 10.3)
        public Reading $reading,          // layer 3: the recorder's reading (operation 'unknown' when no reply)
        public Judgement $judgement,      // layer 4a: the rules' judgement
        public Outcome $outcome,          // layer 4b: the outcome
        public ?string $recorderFailureReason = null, // short machine token why the reading is unknown; null on success (Req 3.6, 10.2)
        public ?string $replyFailureReason = null,     // short machine token why the reply is absent/unanswered; null when answered (Req 10.2)
    ) {}
}
