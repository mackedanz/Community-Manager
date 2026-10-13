-- Sprachkanal eines Termins (optional): Das Discord-Event verweist dann auf diesen Sprachkanal statt auf einen Ort als Text.
ALTER TABLE events
    ADD COLUMN voice_channel_id VARCHAR(32) NULL AFTER location;
