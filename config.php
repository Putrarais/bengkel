<?php
// Kredensial hanya dipakai di server PHP, tidak pernah dikirim ke browser.
// Gunakan service_role key (Project Settings > API) agar RLS publik bisa diperketat nanti.
// Anon key juga bisa selama kebijakan RLS publik dari schema.sql aktif.
const SUPABASE_URL = 'https://YOUR-PROJECT.supabase.co';
const SUPABASE_KEY = 'YOUR-SERVICE-ROLE-OR-ANON-KEY';
