#!/usr/bin/perl
# ============================================================================
# GWOS — helper external_acl do Squid: "este IP tem sessão de login válida?"
# Instalado como /usr/local/sbin/gwos-squid-sessao
# ============================================================================
# Protocolo (uma linha por consulta): recebe o IP do cliente (%SRC) — o Squid pode
# acrescentar campos extras depois dele, que são ignorados — e responde
#     OK user=<e-mail>     sessão válida
#     ERR                  sem sessão / expirada
# Lê /var/lib/gwos/sessions/sessoes.txt (ip<TAB>expira_epoch<TAB>e-mail), gravado de
# forma atômica pelo portal; recarrega sozinho quando o arquivo muda. Não toca
# no banco — o usuário "proxy" do Squid não precisa de credencial nenhuma.
# Em caso de dúvida (arquivo ilegível), NEGA: falha fechada.
# ============================================================================
use strict;
use warnings;

$| = 1;

my $arq = $ENV{GWOS_SESSOES} || '/var/lib/gwos/sessions/sessoes.txt';
my %sessao;                      # ip => [expira, email]
my ($m_mtime, $m_size, $m_ino) = (-1, -1, -1);

sub recarregar {
    my @st = stat($arq);
    unless (@st) { %sessao = (); ($m_mtime, $m_size, $m_ino) = (-1, -1, -1); return; }
    return if $st[9] == $m_mtime && $st[7] == $m_size && $st[1] == $m_ino;

    my %novo;
    if (open(my $fh, '<', $arq)) {
        while (my $l = <$fh>) {
            chomp $l;
            my ($ip, $exp, $mail) = split /\t/, $l, 3;
            next unless defined $mail && $ip =~ /^[0-9a-fA-F:.]+$/ && $exp =~ /^\d+$/;
            $novo{$ip} = [$exp, $mail];
        }
        close $fh;
        %sessao = %novo;
        ($m_mtime, $m_size, $m_ino) = @st[9, 7, 1];
    } else {
        %sessao = ();            # ilegível: ninguém autenticado
    }
}

# Codifica o e-mail como o Squid espera (RFC 1738)
sub codificar {
    my $s = shift;
    $s =~ s/([^A-Za-z0-9._\@-])/sprintf('%%%02X', ord($1))/ge;
    return $s;
}

while (my $linha = <STDIN>) {
    # O Squid envia "IP" seguido de campos extras (ex.: "127.0.0.3 -"): só o
    # primeiro campo é o endereço do cliente.
    my ($ip) = split ' ', $linha;
    $ip = '' unless defined $ip;
    recarregar();
    my $s = $sessao{$ip};
    if ($s && $s->[0] > time()) {
        print 'OK user=' . codificar($s->[1]) . "\n";
    } else {
        print "ERR\n";
    }
}
