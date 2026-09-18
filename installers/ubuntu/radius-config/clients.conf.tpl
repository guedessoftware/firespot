client localhost {
    ipaddr = 127.0.0.1
    secret = ${RADIUS_SECRET}
    require_message_authenticator = no
    nastype = other
}

client ${RADIUS_NAS_NAME} {
    ipaddr = 0.0.0.0/0
    secret = ${RADIUS_SECRET}
    shortname = ${RADIUS_NAS_NAME}
    require_message_authenticator = no
    nastype = other
}
