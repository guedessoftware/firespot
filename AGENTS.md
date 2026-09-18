# Instruções permanentes do projeto FireSpot

## Alterações em MikroTik, Hotspot ou conexão

Antes de analisar ou modificar qualquer código relacionado a MikroTik/RouterOS, NAS, VLAN, DHCP, DNS, perfil Hotspot, `link-login`, RADIUS, cortesia, pagamento, reconexão ou liberação da internet, leia obrigatoriamente:

1. `docs/MIKROTIK_HOTSPOT_CONNECTION_SAFETY.md`;
2. `docs/NAS_MIKROTIK_BASE_PROVISIONING.md`.

Não reintroduza dependência obrigatória de `*.hotspot.internal` no login final do navegador. Para a zona privada automática, preserve a validação do destino e use o `gateway_ip` da instalação correta. Toda mudança desse fluxo deve validar acesso pago, cortesia, patrocinado, reconexão e múltiplas instalações, além de executar os testes de segurança indicados na documentação.

