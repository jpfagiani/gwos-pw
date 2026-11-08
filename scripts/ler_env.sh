#!/bin/bash
# Lê os mesmos valores literais que config/config.php.
gwos_ler_env() {
    local linha chave valor
    [ -f "$1" ] || return 0
    while IFS= read -r linha || [ -n "$linha" ]; do
        [[ "$linha" == *=* ]] || continue
        chave=${linha%%=*}
        valor=${linha#*=}
        chave="${chave#"${chave%%[![:space:]]*}"}"
        chave="${chave%"${chave##*[![:space:]]}"}"
        valor="${valor#"${valor%%[![:space:]]*}"}"
        valor="${valor%"${valor##*[![:space:]]}"}"
        case "$chave" in
            DB_HOST|DB_BANCO|DB_USUARIO|DB_SENHA)
                printf -v "$chave" '%s' "$valor"
                export "$chave"
                ;;
        esac
    done < "$1"
}