-- FireSpot: textos de ação configuráveis por campanha.
-- Somente texto simples; contador e mensagens técnicas permanecem padronizados.

ALTER TABLE custom_ads
  ADD COLUMN IF NOT EXISTS interest_button_text VARCHAR(60) NOT NULL DEFAULT 'Tenho interesse' AFTER fit_mode,
  ADD COLUMN IF NOT EXISTS skip_button_text VARCHAR(60) NOT NULL DEFAULT 'Pular e conectar' AFTER interest_button_text;

UPDATE custom_ads SET interest_button_text='Tenho interesse' WHERE TRIM(interest_button_text)='';
UPDATE custom_ads SET skip_button_text='Pular e conectar' WHERE TRIM(skip_button_text)='';
