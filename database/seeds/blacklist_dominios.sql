-- Seed opcional de domínios bloqueados (não é chamado pelos instaladores).
-- Idempotente: não duplica domínios já cadastrados.
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.facebook.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.facebook.com' AND tipo='blacklist'); -- Facebook
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.instagram.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.instagram.com' AND tipo='blacklist'); -- Instagram
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.twitter.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.twitter.com' AND tipo='blacklist'); -- Twitter/X
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.x.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.x.com' AND tipo='blacklist'); -- Twitter/X
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.tiktok.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.tiktok.com' AND tipo='blacklist'); -- TikTok
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.snapchat.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.snapchat.com' AND tipo='blacklist'); -- Snapchat
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.reddit.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.reddit.com' AND tipo='blacklist'); -- Reddit
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.linkedin.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.linkedin.com' AND tipo='blacklist'); -- LinkedIn
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.pinterest.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.pinterest.com' AND tipo='blacklist'); -- Pinterest
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.youtube.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.youtube.com' AND tipo='blacklist'); -- YouTube
INSERT INTO dominios (dominio, tipo, origem)
SELECT 'youtu.be', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='youtu.be' AND tipo='blacklist'); -- YouTube short
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.googlevideo.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.googlevideo.com' AND tipo='blacklist'); -- YouTube CDN
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.ytimg.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.ytimg.com' AND tipo='blacklist'); -- YouTube imagens
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.netflix.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.netflix.com' AND tipo='blacklist'); -- Netflix
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.globoplay.globo.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.globoplay.globo.com' AND tipo='blacklist'); -- Globoplay
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.twitch.tv', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.twitch.tv' AND tipo='blacklist'); -- Twitch
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.vimeo.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.vimeo.com' AND tipo='blacklist'); -- Vimeo
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.dailymotion.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.dailymotion.com' AND tipo='blacklist'); -- Dailymotion
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.hbomax.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.hbomax.com' AND tipo='blacklist'); -- HBO Max
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.max.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.max.com' AND tipo='blacklist'); -- Max
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.disneyplus.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.disneyplus.com' AND tipo='blacklist'); -- Disney+
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.primevideo.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.primevideo.com' AND tipo='blacklist'); -- Prime Video
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.paramountplus.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.paramountplus.com' AND tipo='blacklist'); -- Paramount+
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.pluto.tv', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.pluto.tv' AND tipo='blacklist'); -- Pluto TV
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.spotify.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.spotify.com' AND tipo='blacklist'); -- Spotify
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.deezer.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.deezer.com' AND tipo='blacklist'); -- Deezer
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.soundcloud.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.soundcloud.com' AND tipo='blacklist'); -- SoundCloud
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.tidal.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.tidal.com' AND tipo='blacklist'); -- Tidal
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.vagalume.com.br', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.vagalume.com.br' AND tipo='blacklist'); -- Vagalume
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.radios.com.br', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.radios.com.br' AND tipo='blacklist'); -- Rádios
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.napster.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.napster.com' AND tipo='blacklist'); -- Napster
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.steampowered.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.steampowered.com' AND tipo='blacklist'); -- Steam
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.epicgames.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.epicgames.com' AND tipo='blacklist'); -- Epic Games
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.roblox.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.roblox.com' AND tipo='blacklist'); -- Roblox
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.uol.com.br', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.uol.com.br' AND tipo='blacklist'); -- UOL
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.terra.com.br', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.terra.com.br' AND tipo='blacklist'); -- Terra
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.r7.com', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.r7.com' AND tipo='blacklist'); -- R7
INSERT INTO dominios (dominio, tipo, origem)
SELECT '.ig.com.br', 'blacklist', 'importado' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio='.ig.com.br' AND tipo='blacklist'); -- iG
