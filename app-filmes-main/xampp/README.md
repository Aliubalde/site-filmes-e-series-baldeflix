# BALDÉ FLIX — edição local para XAMPP

Esta é a versão PHP + MySQL da plataforma, separada da antiga aplicação React que está na raiz do repositório. Para abrir a versão PHP, use os passos abaixo e o endereço `http://localhost/balde-flix/`.

## Requisitos

- XAMPP com Apache e MySQL/MariaDB iniciados.
- PHP 8.1 ou superior, com `PDO MySQL`, `fileinfo` e `mbstring` ativos.
- Apache 2.4 com suporte a `.htaccess` e `AllowOverride All`.

## Instalação local

1. Copie a pasta `xampp` para `C:\xampp\htdocs\balde-flix`.
2. No XAMPP Control Panel, inicie **Apache** e **MySQL**.
3. Abra `http://localhost/phpmyadmin`, selecione **Importar** e escolha `C:\xampp\htdocs\balde-flix\database\schema.sql`.
4. Copie `config.example.php` para `config.php`. No PowerShell, dentro da pasta, pode usar:

   ```powershell
   Copy-Item .\config.example.php .\config.php
   ```

5. Se o MySQL local usar uma palavra-passe para `root`, atualize `db_password` em `config.php`. Não publique nem partilhe esse ficheiro.
6. Execute uma vez `C:\xampp\php\php.exe C:\xampp\htdocs\balde-flix\migrate.php` para aplicar atualizações ao esquema existente. O comando pode ser repetido com segurança.
7. (Opcional) Configure SMTP se quiser disponibilizar recuperação de palavra-passe por e-mail.
8. Abra `http://localhost/balde-flix/setup.php` no próprio computador e crie a conta SUPER ADMIN. Se o e-mail principal já estiver registado como visitante, essa conta será convertida; escolha uma palavra-passe nova, única, com pelo menos 8 caracteres.
9. Inicie sessão em `http://localhost/balde-flix/login.php`.
10. Depois de criar a conta, apague `setup.php` e `migrate.php` da cópia dentro de `htdocs`.

> A configuração inicial está limitada a pedidos originados no próprio computador. Não exponha esta instalação à Internet. Para publicação online, é necessário rever a configuração do servidor, HTTPS, cópias de segurança, política de privacidade, proteção contra abuso e gestão segura de segredos.

## O que já funciona

- Catálogo público responsivo com pesquisa e filtros por tipo e categoria.
- Página de detalhe e reprodução HTML5 quando é carregado um vídeo compatível ou indicada uma fonte HTTPS.
- Registo de visitantes, início e fim de sessão, favoritos e pedidos de filmes/séries.
- Registo com username e acesso automático à conta após o cadastro; a confirmação de e-mail não é necessária.
- Recuperação de palavra-passe por link com expiração e uso único, alteração imediata de e-mail após confirmar a palavra-passe atual e gestão de perfil.
- Perfis com biografia, imagem, favoritos, notificações, pedidos e histórico com retoma do progresso de reprodução.
- Consulta do estado e resposta dos pedidos enviados por uma conta autenticada.
- Painel administrativo com métricas, gestão de filmes/séries, temporadas e episódios, cartazes e upload de vídeos de até 10 GB, pedidos, contas, notificações, redes sociais, comentários, administradores e registo de atividade.
- Cada filme ou série pode ser marcado como gratuito ou pago. Os títulos pagos encaminham o visitante para o WhatsApp configurado pelo SUPER ADMIN; pagamentos são combinados fora do site e não são processados pela plataforma.
- Depois de confirmar o pagamento fora do site, o SUPER ADMIN pode desbloquear individualmente um título para uma conta ou ativar o Premium, que dá acesso a todos os títulos pagos por 30 dias contados desde a ativação. O painel permite revogar cada acesso e terminar uma subscrição; a renovação Premium começa um novo período de 30 dias.
- Papéis SUPER ADMIN, administrador, moderador e visitante; a conta SUPER ADMIN inicial não pode ser suspensa pela interface.
- Palavras-passe com `password_hash`, sessão HTTP-only/SameSite, regeneração de sessão, limite de tentativas de login, consultas preparadas, validação CSRF, uploads até 10 GB de formatos de vídeo reconhecidos pelo PHP Fileinfo (incluindo MP4, MKV, AVI, MOV, WEBM, WMV, FLV, MPEG, TS e 3GP) e imagens (JPG, PNG, WEBP, até 5 MB).
- Alteração da palavra-passe pelo painel, confirmando a palavra-passe atual.

## Configuração SMTP opcional para recuperação de palavra-passe

Para enviar links de recuperação de palavra-passe, preencha no ficheiro local `config.php` a secção `smtp` copiada do `config.example.php`: `host`, `port`, `username`, `password`, `encryption` (`tls` ou `ssl`) e `from_email`. O cadastro e a alteração de e-mail não dependem de envio de mensagens. Use os dados SMTP do seu provedor de e-mail ou uma senha de aplicação. Não envie essa senha por mensagens nem a coloque no repositório. Também ajuste `base_url` para o endereço do site; em alojamento público, tem de começar por `https://`.

Sem SMTP configurado, o cadastro e o acesso à conta continuam a funcionar. Apenas a recuperação de palavra-passe por e-mail fica indisponível.

## Limites desta versão

O upload de vídeos de até 10 GB exige espaço livre em disco e a configuração do PHP no XAMPP com `upload_max_filesize=10G`, `post_max_size=11G`, `max_input_time=3600` e `max_execution_time=3600`; reinicie o Apache depois de alterar `C:\xampp\php\php.ini`. O painel permite escolher vídeos reconhecidos pelo dispositivo e o servidor valida o conteúdo com PHP Fileinfo, incluindo formatos menos comuns identificados como vídeo. Fluxos MPEG-TS são identificados pelo conteúdo mesmo que a extensão esteja errada; ficheiros não identificados como vídeo continuam bloqueados. Para oferecer seleção manual de qualidade, carregue no painel versões preparadas em 360p, 480p, 720p e/ou 1080p; as opções carregadas aparecem no seletor do player, que mantém a posição durante a mudança. O site não converte os vídeos automaticamente. O player permite escolher a proporção original, 16:9, 4:3, 21:9, 1:1 ou preencher, inclusive em ecrã inteiro. As mensagens de transmissão são apresentadas temporariamente. O botão de transmissão usa Remote Playback do navegador ou AirPlay quando disponível; o dispositivo de reprodução precisa de ser compatível e estar acessível na mesma rede. Os vídeos locais são servidos com sessão e pedidos por intervalos; os ficheiros não podem ser acedidos diretamente pela pasta de uploads. Filmes e episódios carregados no site também podem ser descarregados pelo botão na página do título; o download exige sessão e respeita as mesmas permissões de acesso pago. Aceitar um formato não garante que o browser consiga descodificar os codecs dentro do ficheiro; para melhor compatibilidade, use MP4 com vídeo H.264 e áudio AAC. A recuperação depende do SMTP configurado. Cópias de segurança automáticas, configuração completa de banners/comentários públicos e métricas de audiência em tempo real ainda não estão concluídas.

Use apenas cartazes e conteúdos audiovisuais próprios ou com autorização de distribuição. A reprodução requer uma fonte compatível com o elemento HTML5 `<video>` (por exemplo, MP4 servido por HTTPS).

Se uma palavra-passe tiver sido partilhada numa conversa ou mensagem, não a reutilize. Crie outra, única, durante a configuração inicial ou altere-a em **Painel → Segurança → Alterar palavra-passe**. O mínimo aceite é 8 caracteres.
