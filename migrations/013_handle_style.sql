-- Wo im Server-Nickname der RSI-Handle steht (je Orga): plain = außerhalb aller Klammern, round/square/curly/angle = in der
-- ersten passenden Klammer ( ) [ ] { } < >.
ALTER TABLE organizations ADD COLUMN handle_style VARCHAR(10) NOT NULL DEFAULT 'plain';
