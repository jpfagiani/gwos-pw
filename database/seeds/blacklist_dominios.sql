-- GWOS — Seed: Blacklist de domínios padrão
-- Execute: mysql gwos < database/seeds/blacklist_dominios.sql

INSERT IGNORE INTO dominios (dominio, tipo, descricao) VALUES
-- Redes sociais
('.facebook.com',       'blacklist', 'Facebook'),
('.instagram.com',      'blacklist', 'Instagram'),
('.twitter.com',        'blacklist', 'Twitter/X'),
('.x.com',              'blacklist', 'Twitter/X'),
('.tiktok.com',         'blacklist', 'TikTok'),
('.snapchat.com',       'blacklist', 'Snapchat'),
('.reddit.com',         'blacklist', 'Reddit'),
('.linkedin.com',       'blacklist', 'LinkedIn'),
('.pinterest.com',      'blacklist', 'Pinterest'),
-- Streaming de vídeo
('.youtube.com',        'blacklist', 'YouTube'),
('youtu.be',            'blacklist', 'YouTube short'),
('.googlevideo.com',    'blacklist', 'YouTube CDN'),
('.ytimg.com',          'blacklist', 'YouTube imagens'),
('.netflix.com',        'blacklist', 'Netflix'),
('.globoplay.globo.com','blacklist', 'Globoplay'),
('.twitch.tv',          'blacklist', 'Twitch'),
('.vimeo.com',          'blacklist', 'Vimeo'),
('.dailymotion.com',    'blacklist', 'Dailymotion'),
('.hbomax.com',         'blacklist', 'HBO Max'),
('.max.com',            'blacklist', 'Max'),
('.disneyplus.com',     'blacklist', 'Disney+'),
('.primevideo.com',     'blacklist', 'Prime Video'),
('.paramountplus.com',  'blacklist', 'Paramount+'),
('.pluto.tv',           'blacklist', 'Pluto TV'),
-- Música e rádio
('.spotify.com',        'blacklist', 'Spotify'),
('.deezer.com',         'blacklist', 'Deezer'),
('.soundcloud.com',     'blacklist', 'SoundCloud'),
('.tidal.com',          'blacklist', 'Tidal'),
('.vagalume.com.br',    'blacklist', 'Vagalume'),
('.radios.com.br',      'blacklist', 'Rádios'),
('.napster.com',        'blacklist', 'Napster'),
-- Jogos
('.steampowered.com',   'blacklist', 'Steam'),
('.epicgames.com',      'blacklist', 'Epic Games'),
('.roblox.com',         'blacklist', 'Roblox'),
-- Entretenimento geral
('.uol.com.br',         'blacklist', 'UOL'),
('.terra.com.br',       'blacklist', 'Terra'),
('.r7.com',             'blacklist', 'R7'),
('.ig.com.br',          'blacklist', 'iG');
