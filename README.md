# Cinema PCE

Sistema inicial para administracao de cinema com usuarios, filmes, salas e editor visual de poltronas.

## Modulos iniciais

- Usuarios com senha hash e nivel de acesso: `administrador` e `vendedor`.
- Filmes com capa, sinopse, trailer, ficha tecnica, duracao, genero e idioma (`legendado` ou `dublado`).
- Salas com capacidade, quantidade de poltronas normais e grandes.
- Editor de sala com corredores, posicao das poltronas, tipo de poltrona e posicao/tamanho da tela de projecao.
- Estrutura preparada para sessoes, bilhetes e reservas de poltronas.

## Rodando localmente

1. Crie o banco MySQL e rode `database/schema.sql`.
2. Opcionalmente rode `database/seed.sql` para criar um admin inicial.
3. Configure as variaveis de ambiente:

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=cinema_pce
DB_USER=root
DB_PASS=
APP_URL=http://localhost:8080
```

4. Inicie com PHP embutido:

```bash
php -S localhost:8080 -t public
```

## Fly.io

O arquivo `fly.toml` esta pronto para o app `cinema-pce` na regiao `gru`.

Secrets esperados:

```bash
fly secrets set --app cinema-pce DB_PASS="senha-do-banco"
```

Admin inicial do seed:

- Email: `admin@cinema.local`
- Senha: `admin123`

Troque a senha depois do primeiro login.

