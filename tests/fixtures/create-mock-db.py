#!/usr/bin/env python3
"""Generate a preset mock WordPress database fixture on-the-fly for pre-production tests."""

from __future__ import annotations

import argparse
import pathlib
import re
from typing import Any

FIXTURES_DIR = pathlib.Path(__file__).resolve().parent
REPO_ROOT = FIXTURES_DIR.parent.parent
DEFAULT_MYSQL_OUTPUT = FIXTURES_DIR / "wp-mock.sql"
DEFAULT_SITE_URL = "http://127.0.0.2:18080"
DEFAULT_DB_VERSION = "61833"
DEFAULT_TIMESTAMP = "2026-01-01 00:00:00"

ACTIVE_PLUGINS: list[str] = [
    "auto-meta-description/auto-meta-description.php",
    "http-103-early-hints/http-103-early-hints.php",
    "modern-site-icons/modern-site-icons.php",
    "remove-wp-default-link-header/remove-wp-default-link-header.php",
    "security-hardening/security-hardening.php",
]


def php_serialize(value: Any) -> str:
    """Serialize Python primitives, lists, and dicts into exact PHP serialize() format."""
    if value is None:
        return "N;"
    if isinstance(value, bool):
        return f"b:{1 if value else 0};"
    if isinstance(value, int):
        return f"i:{value};"
    if isinstance(value, float):
        return f"d:{value};"
    if isinstance(value, str):
        byte_len = len(value.encode("utf-8"))
        return f's:{byte_len}:"{value}";'
    if isinstance(value, (list, tuple)):
        items = "".join(
            f"{php_serialize(idx)}{php_serialize(item)}"
            for idx, item in enumerate(value)
        )
        return f"a:{len(value)}:{{{items}}}"
    if isinstance(value, dict):
        items = "".join(
            f"{php_serialize(key)}{php_serialize(val)}"
            for key, val in value.items()
        )
        return f"a:{len(value)}:{{{items}}}"
    raise TypeError(f"Unsupported type for PHP serialization: {type(value)!r}")


def detect_wp_db_version(repo_root: pathlib.Path = REPO_ROOT) -> str:
    """Read $wp_db_version from installed WordPress core if available, else fallback."""
    version_file = repo_root / "web" / "wp" / "wp-includes" / "version.php"
    if version_file.is_file():
        content = version_file.read_text(encoding="utf-8", errors="replace")
        match = re.search(r"\$wp_db_version\s*=\s*(\d+)\s*;", content)
        if match:
            return match.group(1)
    return DEFAULT_DB_VERSION


def build_wp_user_roles() -> dict[str, dict[str, Any]]:
    """Build standard WordPress roles and capabilities matching populate_roles()."""
    admin_caps = {
        "switch_themes": True,
        "edit_themes": True,
        "activate_plugins": True,
        "edit_plugins": True,
        "edit_users": True,
        "edit_files": True,
        "manage_options": True,
        "moderate_comments": True,
        "manage_categories": True,
        "manage_links": True,
        "upload_files": True,
        "import": True,
        "unfiltered_html": True,
        "edit_posts": True,
        "edit_others_posts": True,
        "edit_published_posts": True,
        "publish_posts": True,
        "edit_pages": True,
        "read": True,
        "level_10": True,
        "level_9": True,
        "level_8": True,
        "level_7": True,
        "level_6": True,
        "level_5": True,
        "level_4": True,
        "level_3": True,
        "level_2": True,
        "level_1": True,
        "level_0": True,
        "edit_others_pages": True,
        "edit_published_pages": True,
        "publish_pages": True,
        "delete_pages": True,
        "delete_others_pages": True,
        "delete_published_pages": True,
        "delete_posts": True,
        "delete_others_posts": True,
        "delete_published_posts": True,
        "delete_private_posts": True,
        "edit_private_posts": True,
        "read_private_posts": True,
        "delete_private_pages": True,
        "edit_private_pages": True,
        "read_private_pages": True,
        "delete_users": True,
        "create_users": True,
        "unfiltered_upload": True,
        "edit_dashboard": True,
        "update_plugins": True,
        "delete_plugins": True,
        "install_plugins": True,
        "update_themes": True,
        "install_themes": True,
        "update_core": True,
        "list_users": True,
        "remove_users": True,
        "promote_users": True,
        "edit_theme_options": True,
        "delete_themes": True,
        "export": True,
    }

    editor_caps = {
        "moderate_comments": True,
        "manage_categories": True,
        "manage_links": True,
        "upload_files": True,
        "unfiltered_html": True,
        "edit_posts": True,
        "edit_others_posts": True,
        "edit_published_posts": True,
        "publish_posts": True,
        "edit_pages": True,
        "read": True,
        "level_7": True,
        "level_6": True,
        "level_5": True,
        "level_4": True,
        "level_3": True,
        "level_2": True,
        "level_1": True,
        "level_0": True,
        "edit_others_pages": True,
        "edit_published_pages": True,
        "publish_pages": True,
        "delete_pages": True,
        "delete_others_pages": True,
        "delete_published_pages": True,
        "delete_posts": True,
        "delete_others_posts": True,
        "delete_published_posts": True,
        "delete_private_posts": True,
        "edit_private_posts": True,
        "read_private_posts": True,
        "delete_private_pages": True,
        "edit_private_pages": True,
        "read_private_pages": True,
    }

    author_caps = {
        "upload_files": True,
        "edit_posts": True,
        "edit_published_posts": True,
        "publish_posts": True,
        "read": True,
        "level_2": True,
        "level_1": True,
        "level_0": True,
        "delete_posts": True,
        "delete_published_posts": True,
    }

    contributor_caps = {
        "edit_posts": True,
        "read": True,
        "level_1": True,
        "level_0": True,
        "delete_posts": True,
    }

    subscriber_caps = {
        "read": True,
        "level_0": True,
    }

    return {
        "administrator": {"name": "Administrator", "capabilities": admin_caps},
        "editor": {"name": "Editor", "capabilities": editor_caps},
        "author": {"name": "Author", "capabilities": author_caps},
        "contributor": {"name": "Contributor", "capabilities": contributor_caps},
        "subscriber": {"name": "Subscriber", "capabilities": subscriber_caps},
    }


def build_seed_options(site_url: str, db_version: str) -> list[tuple[str, str, str]]:
    """Return preset (option_name, option_value, autoload) rows for wp_options."""
    normalized_url = site_url.rstrip("/")
    empty_array = php_serialize([])

    # Note: `rewrite_rules` is intentionally omitted so `get_option('rewrite_rules')` returns false
    # and `WP_Rewrite::wp_rewrite_rules()` compiles fresh rules (including `/wp-json/` REST routes)
    # on first request under `permalink_structure = '/%postname%/'`.
    return [
        ("siteurl", f"{normalized_url}/wp", "on"),
        ("home", normalized_url, "on"),
        ("blogname", "Legispect Pre-Production", "on"),
        ("blogdescription", "Pre-Production Mock Database", "on"),
        ("users_can_register", "0", "on"),
        ("admin_email", "admin@example.com", "on"),
        ("start_of_week", "1", "on"),
        ("use_balanceTags", "0", "on"),
        ("use_smilies", "1", "on"),
        ("require_name_email", "1", "on"),
        ("comments_notify", "1", "on"),
        ("posts_per_rss", "10", "on"),
        ("rss_use_excerpt", "0", "on"),
        ("mailserver_url", "mail.example.com", "on"),
        ("mailserver_login", "login@example.com", "on"),
        ("mailserver_pass", "", "on"),
        ("mailserver_port", "110", "on"),
        ("default_category", "1", "on"),
        ("default_comment_status", "open", "on"),
        ("default_ping_status", "open", "on"),
        ("default_pingback_flag", "1", "on"),
        ("posts_per_page", "10", "on"),
        ("date_format", "F j, Y", "on"),
        ("time_format", "g:i a", "on"),
        ("links_updated_date_format", "F j, Y g:i a", "on"),
        ("comment_moderation", "0", "on"),
        ("moderation_notify", "1", "on"),
        ("permalink_structure", "/%postname%/", "on"),
        ("hack_file", "0", "on"),
        ("blog_charset", "UTF-8", "on"),
        ("moderation_keys", "", "off"),
        ("active_plugins", php_serialize(ACTIVE_PLUGINS), "on"),
        ("category_base", "", "on"),
        ("ping_sites", "https://rpc.pingomatic.com/", "on"),
        ("comment_max_links", "2", "on"),
        ("gmt_offset", "0", "on"),
        ("default_email_category", "1", "on"),
        ("recently_edited", "", "off"),
        ("template", "twentytwentyfour", "on"),
        ("stylesheet", "twentytwentyfour", "on"),
        ("comment_registration", "0", "on"),
        ("html_type", "text/html", "on"),
        ("use_trackback", "0", "on"),
        ("default_role", "subscriber", "on"),
        ("db_version", db_version, "on"),
        ("initial_db_version", db_version, "on"),
        ("uploads_use_yearmonth_folders", "1", "on"),
        ("upload_path", "", "on"),
        ("blog_public", "1", "on"),
        ("default_link_category", "2", "on"),
        ("show_on_front", "posts", "on"),
        ("tag_base", "", "on"),
        ("show_avatars", "1", "on"),
        ("avatar_rating", "G", "on"),
        ("upload_url_path", "", "on"),
        ("thumbnail_size_w", "150", "on"),
        ("thumbnail_size_h", "150", "on"),
        ("thumbnail_crop", "1", "on"),
        ("medium_size_w", "300", "on"),
        ("medium_size_h", "300", "on"),
        ("avatar_default", "mystery", "on"),
        ("large_size_w", "1024", "on"),
        ("large_size_h", "1024", "on"),
        ("image_default_link_type", "none", "on"),
        ("image_default_size", "", "on"),
        ("image_default_align", "", "on"),
        ("close_comments_for_old_posts", "0", "on"),
        ("close_comments_days_old", "14", "on"),
        ("thread_comments", "1", "on"),
        ("thread_comments_depth", "5", "on"),
        ("page_comments", "0", "on"),
        ("comments_per_page", "50", "on"),
        ("default_comments_page", "newest", "on"),
        ("comment_order", "asc", "on"),
        ("sticky_posts", empty_array, "on"),
        ("widget_categories", empty_array, "on"),
        ("widget_text", empty_array, "on"),
        ("widget_rss", empty_array, "on"),
        ("uninstall_plugins", empty_array, "off"),
        ("timezone_string", "", "on"),
        ("page_for_posts", "0", "on"),
        ("page_on_front", "0", "on"),
        ("default_post_format", "0", "on"),
        ("link_manager_enabled", "0", "on"),
        ("finished_splitting_shared_terms", "1", "on"),
        ("site_icon", "0", "on"),
        ("medium_large_size_w", "768", "on"),
        ("medium_large_size_h", "0", "on"),
        ("wp_page_for_privacy_policy", "0", "on"),
        ("show_comments_cookies_opt_in", "1", "on"),
        ("disallowed_keys", "", "off"),
        ("comment_previously_approved", "1", "on"),
        ("auto_plugin_theme_update_emails", empty_array, "off"),
        ("auto_update_core_dev", "enabled", "on"),
        ("auto_update_core_minor", "enabled", "on"),
        ("auto_update_core_major", "enabled", "on"),
        ("wp_force_deactivated_plugins", empty_array, "on"),
        ("wp_attachment_pages_enabled", "0", "on"),
        ("wp_notes_notify", "1", "on"),
        ("WPLANG", "", "on"),
        ("fresh_site", "0", "off"),
        ("wp_user_roles", php_serialize(build_wp_user_roles()), "on"),
    ]


def sql_quote(value: Any) -> str:
    """Escape a value as a MySQL/MariaDB SQL literal."""
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, int):
        return str(value)
    text = str(value)
    escaped = (
        text.replace("\\", "\\\\")
        .replace("'", "\\'")
        .replace("\0", "\\0")
        .replace("\n", "\\n")
        .replace("\r", "\\r")
        .replace("\x1a", "\\Z")
    )
    return f"'{escaped}'"


def generate_mysql_dump(site_url: str, db_version: str) -> str:
    """Generate a MySQL 8 / MariaDB 11 compatible SQL dump for WordPress."""
    normalized_url = site_url.rstrip("/")
    table_opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci"

    schema_sql = f"""-- Preset Mock WordPress Database (MySQL 8 / MariaDB 11)
-- Generated on-the-fly by tests/fixtures/create-mock-db.py
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `wp_termmeta`;
DROP TABLE IF EXISTS `wp_terms`;
DROP TABLE IF EXISTS `wp_term_taxonomy`;
DROP TABLE IF EXISTS `wp_term_relationships`;
DROP TABLE IF EXISTS `wp_commentmeta`;
DROP TABLE IF EXISTS `wp_comments`;
DROP TABLE IF EXISTS `wp_links`;
DROP TABLE IF EXISTS `wp_options`;
DROP TABLE IF EXISTS `wp_postmeta`;
DROP TABLE IF EXISTS `wp_posts`;
DROP TABLE IF EXISTS `wp_usermeta`;
DROP TABLE IF EXISTS `wp_users`;

CREATE TABLE `wp_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT 0,
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) {table_opts};

CREATE TABLE `wp_usermeta` (
  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`umeta_id`),
  KEY `user_id` (`user_id`),
  KEY `meta_key` (`meta_key`(191))
) {table_opts};

CREATE TABLE `wp_termmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `term_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `term_id` (`term_id`),
  KEY `meta_key` (`meta_key`(191))
) {table_opts};

CREATE TABLE `wp_terms` (
  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '',
  `slug` varchar(200) NOT NULL DEFAULT '',
  `term_group` bigint(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_id`),
  KEY `slug` (`slug`(191)),
  KEY `name` (`name`(191))
) {table_opts};

CREATE TABLE `wp_term_taxonomy` (
  `term_taxonomy_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `term_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `taxonomy` varchar(32) NOT NULL DEFAULT '',
  `description` longtext NOT NULL,
  `parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `count` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_taxonomy_id`),
  UNIQUE KEY `term_id_taxonomy` (`term_id`,`taxonomy`),
  KEY `taxonomy` (`taxonomy`)
) {table_opts};

CREATE TABLE `wp_term_relationships` (
  `object_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `term_taxonomy_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `term_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`object_id`,`term_taxonomy_id`),
  KEY `term_taxonomy_id` (`term_taxonomy_id`)
) {table_opts};

CREATE TABLE `wp_commentmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `comment_id` (`comment_id`),
  KEY `meta_key` (`meta_key`(191))
) {table_opts};

CREATE TABLE `wp_comments` (
  `comment_ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `comment_post_ID` bigint(20) unsigned NOT NULL DEFAULT 0,
  `comment_author` tinytext NOT NULL,
  `comment_author_email` varchar(100) NOT NULL DEFAULT '',
  `comment_author_url` varchar(200) NOT NULL DEFAULT '',
  `comment_author_IP` varchar(100) NOT NULL DEFAULT '',
  `comment_date` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `comment_date_gmt` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `comment_content` text NOT NULL,
  `comment_karma` int(11) NOT NULL DEFAULT 0,
  `comment_approved` varchar(20) NOT NULL DEFAULT '1',
  `comment_agent` varchar(255) NOT NULL DEFAULT '',
  `comment_type` varchar(20) NOT NULL DEFAULT 'comment',
  `comment_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`comment_ID`),
  KEY `comment_post_ID` (`comment_post_ID`),
  KEY `comment_approved_date_gmt` (`comment_approved`,`comment_date_gmt`),
  KEY `comment_date_gmt` (`comment_date_gmt`),
  KEY `comment_parent` (`comment_parent`),
  KEY `comment_author_email` (`comment_author_email`(10))
) {table_opts};

CREATE TABLE `wp_links` (
  `link_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `link_url` varchar(255) NOT NULL DEFAULT '',
  `link_name` varchar(255) NOT NULL DEFAULT '',
  `link_image` varchar(255) NOT NULL DEFAULT '',
  `link_target` varchar(25) NOT NULL DEFAULT '',
  `link_description` varchar(255) NOT NULL DEFAULT '',
  `link_visible` varchar(20) NOT NULL DEFAULT 'Y',
  `link_owner` bigint(20) unsigned NOT NULL DEFAULT 1,
  `link_rating` int(11) NOT NULL DEFAULT 0,
  `link_updated` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `link_rel` varchar(255) NOT NULL DEFAULT '',
  `link_notes` mediumtext NOT NULL,
  `link_rss` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`link_id`),
  KEY `link_visible` (`link_visible`)
) {table_opts};

CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) {table_opts};

CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) {table_opts};

CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT 0,
  `post_date` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `post_date_gmt` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) NOT NULL DEFAULT 'open',
  `post_password` varchar(255) NOT NULL DEFAULT '',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `to_ping` text NOT NULL,
  `pinged` text NOT NULL,
  `post_modified` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `post_modified_gmt` datetime NOT NULL DEFAULT '{DEFAULT_TIMESTAMP}',
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT 0,
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`),
  KEY `post_author` (`post_author`),
  KEY `type_status_author` (`post_type`,`post_status`,`post_author`)
) {table_opts};
"""

    options = build_seed_options(site_url, db_version)
    option_tuples = ",\n  ".join(
        f"({sql_quote(name)}, {sql_quote(val)}, {sql_quote(autoload)})"
        for name, val, autoload in options
    )

    admin_caps_serialized = php_serialize({"administrator": True})
    usermeta_rows = [
        (1, "nickname", "admin"),
        (1, "first_name", ""),
        (1, "last_name", ""),
        (1, "description", ""),
        (1, "rich_editing", "true"),
        (1, "syntax_highlighting", "true"),
        (1, "comment_shortcuts", "false"),
        (1, "admin_color", "fresh"),
        (1, "use_ssl", "0"),
        (1, "show_admin_bar_front", "true"),
        (1, "locale", ""),
        (1, "wp_capabilities", admin_caps_serialized),
        (1, "wp_user_level", "10"),
    ]
    usermeta_tuples = ",\n  ".join(
        f"({uid}, {sql_quote(mkey)}, {sql_quote(mval)})"
        for uid, mkey, mval in usermeta_rows
    )

    post_content = "<!-- wp:paragraph -->\n<p>Pre-production mock post.</p>\n<!-- /wp:paragraph -->"
    post_guid = f"{normalized_url}/?p=1"

    data_sql = f"""
INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES
  {option_tuples};

INSERT INTO `wp_users` (`ID`, `user_login`, `user_pass`, `user_nicename`, `user_email`, `user_url`, `user_registered`, `user_activation_key`, `user_status`, `display_name`) VALUES
  (1, 'admin', {sql_quote('$P$Bmockhashfortestingonly')}, 'admin', 'admin@example.com', {sql_quote(f'{normalized_url}/wp')}, '{DEFAULT_TIMESTAMP}', '', 0, 'Admin');

INSERT INTO `wp_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES
  {usermeta_tuples};

INSERT INTO `wp_terms` (`term_id`, `name`, `slug`, `term_group`) VALUES
  (1, 'Uncategorized', 'uncategorized', 0);

INSERT INTO `wp_term_taxonomy` (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`, `parent`, `count`) VALUES
  (1, 1, 'category', '', 0, 1);

INSERT INTO `wp_posts` (`ID`, `post_author`, `post_date`, `post_date_gmt`, `post_content`, `post_title`, `post_excerpt`, `post_status`, `comment_status`, `ping_status`, `post_password`, `post_name`, `to_ping`, `pinged`, `post_modified`, `post_modified_gmt`, `post_content_filtered`, `post_parent`, `guid`, `menu_order`, `post_type`, `post_mime_type`, `comment_count`) VALUES
  (1, 1, '{DEFAULT_TIMESTAMP}', '{DEFAULT_TIMESTAMP}', {sql_quote(post_content)}, 'Hello World', '', 'publish', 'open', 'open', '', 'hello-world', '', '', '{DEFAULT_TIMESTAMP}', '{DEFAULT_TIMESTAMP}', '', 0, {sql_quote(post_guid)}, 0, 'post', '', 0);

INSERT INTO `wp_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES
  (1, 1, 0);

SET foreign_key_checks = 1;
"""
    return schema_sql + data_sql


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate a preset mock WordPress MySQL 8.4 / MariaDB 11 SQL dump on-the-fly."
    )
    parser.add_argument(
        "--output",
        type=pathlib.Path,
        default=DEFAULT_MYSQL_OUTPUT,
        help="Destination path for the generated SQL dump (default: tests/fixtures/wp-mock.sql).",
    )
    parser.add_argument(
        "--site-url",
        default=DEFAULT_SITE_URL,
        help=f"Base WordPress home URL without /wp suffix (default: {DEFAULT_SITE_URL}).",
    )
    parser.add_argument(
        "--db-version",
        default=None,
        help="Override WordPress db_version (defaults to installed version.php or 61833).",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> None:
    args = parse_args(argv)
    db_version = args.db_version or detect_wp_db_version()

    output_path: pathlib.Path = args.output
    output_path.parent.mkdir(parents=True, exist_ok=True)
    sql_dump = generate_mysql_dump(args.site_url, db_version)
    output_path.write_text(sql_dump, encoding="utf-8")

    print(f"Created {output_path} (site_url={args.site_url}, db_version={db_version})")


if __name__ == "__main__":
    main()
