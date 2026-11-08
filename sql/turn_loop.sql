-- Turn-loop tables for Poltergeist Switch.
-- MySQL, InnoDB, utf8mb4 everywhere so multibyte text (Japanese resident lines,
-- robot replies) is stored without loss. This file holds schema only
-- (Requirements 2.3, 8.1).

CREATE TABLE episodes (
  id                    CHAR(36)     NOT NULL,
  device_id             VARCHAR(64)  NOT NULL,
  disaster              VARCHAR(16)  NOT NULL DEFAULT 'earthquake',
  seed                  BIGINT       NOT NULL,
  current_expected_turn INT          NOT NULL DEFAULT 0,
  earthquake_start_turn INT          NULL,
  ended                 TINYINT(1)   NOT NULL DEFAULT 0,
  created_at            DATETIME     NOT NULL,
  last_activity_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_running (ended, last_activity_at),
  KEY idx_device (device_id, ended)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE turns (
  episode_id    CHAR(36)    NOT NULL,
  turn_number   INT         NOT NULL,
  state         VARCHAR(16) NOT NULL DEFAULT 'waiting',
  attempts_a    INT         NOT NULL DEFAULT 0,
  attempts_b    INT         NOT NULL DEFAULT 0,
  no_reply_a    TINYINT(1)  NOT NULL DEFAULT 0,
  no_reply_b    TINYINT(1)  NOT NULL DEFAULT 0,
  resident_line TEXT        NULL,
  created_at    DATETIME    NOT NULL,
  PRIMARY KEY (episode_id, turn_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prompts (
  episode_id             CHAR(36)    NOT NULL,
  turn_number            INT         NOT NULL,
  robot_id               VARCHAR(16) NOT NULL,
  prompt_text            MEDIUMTEXT  NOT NULL,
  situation_text_version VARCHAR(32) NULL,
  PRIMARY KEY (episode_id, turn_number, robot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE responses (
  episode_id    CHAR(36)     NOT NULL,
  turn_number   INT          NOT NULL,
  robot_id      VARCHAR(16)  NOT NULL,
  attempt       INT          NOT NULL,
  reply_text    MEDIUMTEXT   NOT NULL,
  input_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  seconds       DOUBLE       NOT NULL DEFAULT 0,
  cost          DOUBLE       NOT NULL DEFAULT 0,
  unanswered    TINYINT(1)   NOT NULL DEFAULT 0,
  truncated     TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (episode_id, turn_number, robot_id, attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recordings (
  episode_id              CHAR(36)    NOT NULL,
  turn_number             INT         NOT NULL,
  robot_id                VARCHAR(16) NOT NULL,
  recorder_json           MEDIUMTEXT  NOT NULL,
  unknown                 TINYINT(1)  NOT NULL DEFAULT 0,
  recorder_prompt_version VARCHAR(32) NOT NULL,
  PRIMARY KEY (episode_id, turn_number, robot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE actions (
  episode_id     CHAR(36)    NOT NULL,
  turn_number    INT         NOT NULL,
  robot_id       VARCHAR(16) NOT NULL,
  judgement_json MEDIUMTEXT  NOT NULL,
  outcome_json   MEDIUMTEXT  NOT NULL,
  action_json    MEDIUMTEXT  NOT NULL,
  PRIMARY KEY (episode_id, turn_number, robot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
