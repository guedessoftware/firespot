-- Suporte à revalidação automática do benefício sem armazenar CPF/CNPJ em texto aberto.
ALTER TABLE subscriber_external_links
  ADD COLUMN IF NOT EXISTS document_encrypted TEXT NULL AFTER document_hash;

