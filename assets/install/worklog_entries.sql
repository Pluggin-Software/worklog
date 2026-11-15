create table worklog_entries
(
    worklog_entries_id int unsigned auto_increment primary key,
    user_id            int                  not null comment 'the writer; every read and write binds it from the session',
    logged_at          datetime             not null comment 'UTC; shown in the site zone',
    body               varchar(500)         not null comment 'plain text, never HTML',
    deleted            tinyint(1) default 0 not null,
    date_created       datetime             null comment 'UTC',
    date_updated       datetime             null comment 'UTC',
    index worklog_entries_user_logged (user_id, logged_at)
)
    comment 'Work Log module: one what-I-just-did entry'
    charset = utf8mb4
    collate = utf8mb4_unicode_ci;
