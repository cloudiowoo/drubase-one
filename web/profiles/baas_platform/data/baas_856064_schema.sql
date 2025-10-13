--
-- PostgreSQL database dump
--


-- Dumped from database version 17.6 (Debian 17.6-1.pgdg13+1)
-- Dumped by pg_dump version 17.6 (Debian 17.6-1.pgdg13+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_table_access_method = heap;

--
-- Name: baas_856064_activities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.baas_856064_activities (
    id integer NOT NULL,
    uuid character varying(128) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    tenant_id character varying(64) DEFAULT 'tenant_7375b0cd'::character varying NOT NULL,
    project_id character varying(64) DEFAULT 'tenant_7375b0cd_project_6888d012be80c'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    location text,
    activity_date character varying(20) NOT NULL,
    activity_time character varying(255) NOT NULL,
    sport_type character varying(255) NOT NULL,
    team_count integer NOT NULL,
    players_per_team integer NOT NULL,
    status character varying(255),
    creator_id integer NOT NULL,
    is_locked boolean DEFAULT false NOT NULL,
    is_demo boolean DEFAULT false NOT NULL,
    is_creator_demo boolean DEFAULT false NOT NULL,
    participants_visibility character varying(255) NOT NULL,
    style character varying(255)
);


--
-- Name: TABLE baas_856064_activities; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.baas_856064_activities IS '存储项目 tenant_7375b0cd_project_6888d012be80c 中 activities 实体的数据';


--
-- Name: COLUMN baas_856064_activities.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.id IS '主键';


--
-- Name: COLUMN baas_856064_activities.uuid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.uuid IS 'UUID标识';


--
-- Name: COLUMN baas_856064_activities.created; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.created IS '创建时间';


--
-- Name: COLUMN baas_856064_activities.updated; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.updated IS '修改时间';


--
-- Name: COLUMN baas_856064_activities.tenant_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.tenant_id IS '租户ID';


--
-- Name: COLUMN baas_856064_activities.project_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.project_id IS '项目ID';


--
-- Name: COLUMN baas_856064_activities.title; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.title IS 'title';


--
-- Name: COLUMN baas_856064_activities.description; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.description IS 'description';


--
-- Name: COLUMN baas_856064_activities.location; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.location IS 'location';


--
-- Name: COLUMN baas_856064_activities.activity_date; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.activity_date IS 'activity_date';


--
-- Name: COLUMN baas_856064_activities.activity_time; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.activity_time IS 'activity_time';


--
-- Name: COLUMN baas_856064_activities.sport_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.sport_type IS 'sport_type';


--
-- Name: COLUMN baas_856064_activities.team_count; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.team_count IS 'team_count';


--
-- Name: COLUMN baas_856064_activities.players_per_team; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.players_per_team IS 'players_per_team';


--
-- Name: COLUMN baas_856064_activities.status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.status IS 'status';


--
-- Name: COLUMN baas_856064_activities.creator_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.creator_id IS 'creator_id';


--
-- Name: COLUMN baas_856064_activities.participants_visibility; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.participants_visibility IS 'participants_visibility';


--
-- Name: COLUMN baas_856064_activities.style; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_activities.style IS 'style';


--
-- Name: baas_00403b_activities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.baas_00403b_activities_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: baas_00403b_activities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.baas_00403b_activities_id_seq OWNED BY public.baas_856064_activities.id;


--
-- Name: baas_856064_positions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.baas_856064_positions (
    id integer NOT NULL,
    uuid character varying(128) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    tenant_id character varying(64) DEFAULT 'tenant_7375b0cd'::character varying NOT NULL,
    project_id character varying(64) DEFAULT 'tenant_7375b0cd_project_6888d012be80c'::character varying NOT NULL,
    activity_id integer NOT NULL,
    name character varying(255) NOT NULL,
    is_locked boolean DEFAULT false NOT NULL,
    team_id integer NOT NULL,
    user_id integer,
    custom_user_name character varying(255)
);


--
-- Name: TABLE baas_856064_positions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.baas_856064_positions IS '存储项目 tenant_7375b0cd_project_6888d012be80c 中 positions 实体的数据';


--
-- Name: COLUMN baas_856064_positions.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.id IS '主键';


--
-- Name: COLUMN baas_856064_positions.uuid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.uuid IS 'UUID标识';


--
-- Name: COLUMN baas_856064_positions.created; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.created IS '创建时间';


--
-- Name: COLUMN baas_856064_positions.updated; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.updated IS '修改时间';


--
-- Name: COLUMN baas_856064_positions.tenant_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.tenant_id IS '租户ID';


--
-- Name: COLUMN baas_856064_positions.project_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.project_id IS '项目ID';


--
-- Name: COLUMN baas_856064_positions.activity_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.activity_id IS 'activity_id';


--
-- Name: COLUMN baas_856064_positions.name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.name IS 'name';


--
-- Name: COLUMN baas_856064_positions.team_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.team_id IS 'team_id';


--
-- Name: COLUMN baas_856064_positions.user_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.user_id IS 'user_id';


--
-- Name: COLUMN baas_856064_positions.custom_user_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_positions.custom_user_name IS '自定义用户名称，用于创建者指定匿名用户（非系统用户）占用座位';


--
-- Name: baas_00403b_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.baas_00403b_positions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: baas_00403b_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.baas_00403b_positions_id_seq OWNED BY public.baas_856064_positions.id;


--
-- Name: baas_856064_teams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.baas_856064_teams (
    id integer NOT NULL,
    uuid character varying(128) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    tenant_id character varying(64) DEFAULT 'tenant_7375b0cd'::character varying NOT NULL,
    project_id character varying(64) DEFAULT 'tenant_7375b0cd_project_6888d012be80c'::character varying NOT NULL,
    activity_id integer NOT NULL,
    name character varying(255) NOT NULL,
    color character varying(255) NOT NULL,
    logo character varying(255)
);


--
-- Name: TABLE baas_856064_teams; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.baas_856064_teams IS '存储项目 tenant_7375b0cd_project_6888d012be80c 中 teams 实体的数据';


--
-- Name: COLUMN baas_856064_teams.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.id IS '主键';


--
-- Name: COLUMN baas_856064_teams.uuid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.uuid IS 'UUID标识';


--
-- Name: COLUMN baas_856064_teams.created; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.created IS '创建时间';


--
-- Name: COLUMN baas_856064_teams.updated; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.updated IS '修改时间';


--
-- Name: COLUMN baas_856064_teams.tenant_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.tenant_id IS '租户ID';


--
-- Name: COLUMN baas_856064_teams.project_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.project_id IS '项目ID';


--
-- Name: COLUMN baas_856064_teams.activity_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.activity_id IS 'activity_id';


--
-- Name: COLUMN baas_856064_teams.name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.name IS 'name';


--
-- Name: COLUMN baas_856064_teams.color; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.color IS 'color';


--
-- Name: COLUMN baas_856064_teams.logo; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_teams.logo IS 'logo';


--
-- Name: baas_00403b_teams_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.baas_00403b_teams_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: baas_00403b_teams_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.baas_00403b_teams_id_seq OWNED BY public.baas_856064_teams.id;


--
-- Name: baas_856064_user_activities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.baas_856064_user_activities (
    id integer NOT NULL,
    uuid character varying(128) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    tenant_id character varying(64) DEFAULT 'tenant_7375b0cd'::character varying NOT NULL,
    project_id character varying(64) DEFAULT 'tenant_7375b0cd_project_6888d012be80c'::character varying NOT NULL,
    user_id integer NOT NULL,
    activity_id integer NOT NULL,
    team_id integer DEFAULT 0,
    position_id integer DEFAULT 0,
    status character varying(255) NOT NULL,
    display_name character varying(255)
);


--
-- Name: TABLE baas_856064_user_activities; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.baas_856064_user_activities IS '存储项目 tenant_7375b0cd_project_6888d012be80c 中 user_activities 实体的数据';


--
-- Name: COLUMN baas_856064_user_activities.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.id IS '主键';


--
-- Name: COLUMN baas_856064_user_activities.uuid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.uuid IS 'UUID标识';


--
-- Name: COLUMN baas_856064_user_activities.created; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.created IS '创建时间';


--
-- Name: COLUMN baas_856064_user_activities.updated; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.updated IS '修改时间';


--
-- Name: COLUMN baas_856064_user_activities.tenant_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.tenant_id IS '租户ID';


--
-- Name: COLUMN baas_856064_user_activities.project_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.project_id IS '项目ID';


--
-- Name: COLUMN baas_856064_user_activities.user_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.user_id IS 'user_id';


--
-- Name: COLUMN baas_856064_user_activities.activity_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.activity_id IS 'activity_id';


--
-- Name: COLUMN baas_856064_user_activities.team_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.team_id IS 'team_id';


--
-- Name: COLUMN baas_856064_user_activities.position_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.position_id IS 'position_id';


--
-- Name: COLUMN baas_856064_user_activities.status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.status IS 'status';


--
-- Name: COLUMN baas_856064_user_activities.display_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_user_activities.display_name IS '用户在特定活动中的显示名称，可以与用户主名不同';


--
-- Name: baas_00403b_user_activities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.baas_00403b_user_activities_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: baas_00403b_user_activities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.baas_00403b_user_activities_id_seq OWNED BY public.baas_856064_user_activities.id;


--
-- Name: baas_856064_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.baas_856064_users (
    id integer NOT NULL,
    uuid character varying(128) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    tenant_id character varying(64) DEFAULT 'tenant_7375b0cd'::character varying NOT NULL,
    project_id character varying(64) DEFAULT 'tenant_7375b0cd_project_6888d012be80c'::character varying NOT NULL,
    username character varying(255) NOT NULL,
    email character varying(254),
    wx_open_id character varying(255),
    wx_session_key character varying(255),
    provider character varying(255),
    role character varying(255),
    avatar_url text,
    avatar character varying(255),
    is_locked boolean DEFAULT false NOT NULL,
    is_demo boolean DEFAULT false NOT NULL,
    is_temporary boolean DEFAULT false NOT NULL,
    last_login_at integer,
    phone character varying(255),
    password character varying(255) NOT NULL
);


--
-- Name: TABLE baas_856064_users; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.baas_856064_users IS '存储项目 tenant_7375b0cd_project_6888d012be80c 中 users 实体的数据';


--
-- Name: COLUMN baas_856064_users.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.id IS '主键';


--
-- Name: COLUMN baas_856064_users.uuid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.uuid IS 'UUID标识';


--
-- Name: COLUMN baas_856064_users.created; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.created IS '创建时间';


--
-- Name: COLUMN baas_856064_users.updated; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.updated IS '修改时间';


--
-- Name: COLUMN baas_856064_users.tenant_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.tenant_id IS '租户ID';


--
-- Name: COLUMN baas_856064_users.project_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.project_id IS '项目ID';


--
-- Name: COLUMN baas_856064_users.username; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.username IS 'username';


--
-- Name: COLUMN baas_856064_users.email; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.email IS 'email';


--
-- Name: COLUMN baas_856064_users.wx_open_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.wx_open_id IS 'wx_open_id';


--
-- Name: COLUMN baas_856064_users.wx_session_key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.wx_session_key IS 'wx_session_key';


--
-- Name: COLUMN baas_856064_users.provider; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.provider IS 'provider';


--
-- Name: COLUMN baas_856064_users.role; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.role IS 'role';


--
-- Name: COLUMN baas_856064_users.avatar_url; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.avatar_url IS 'avatar_url';


--
-- Name: COLUMN baas_856064_users.avatar; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.avatar IS 'avatar';


--
-- Name: COLUMN baas_856064_users.last_login_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.last_login_at IS 'last_login_at';


--
-- Name: COLUMN baas_856064_users.phone; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.phone IS 'phone';


--
-- Name: COLUMN baas_856064_users.password; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_users.password IS 'password';


--
-- Name: baas_00403b_users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.baas_00403b_users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: baas_00403b_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.baas_00403b_users_id_seq OWNED BY public.baas_856064_users.id;


--
-- Name: baas_856064_system_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.baas_856064_system_config (
    id integer NOT NULL,
    uuid character varying(128) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    tenant_id character varying(64) DEFAULT 'tenant_7375b0cd'::character varying NOT NULL,
    project_id character varying(64) DEFAULT 'tenant_7375b0cd_project_6888d012be80c'::character varying NOT NULL,
    key character varying(255) NOT NULL,
    value jsonb DEFAULT '""'::jsonb NOT NULL
);


--
-- Name: TABLE baas_856064_system_config; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.baas_856064_system_config IS '存储项目 tenant_7375b0cd_project_6888d012be80c 中 system_config 实体的数据';


--
-- Name: COLUMN baas_856064_system_config.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.id IS '主键';


--
-- Name: COLUMN baas_856064_system_config.uuid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.uuid IS 'UUID标识';


--
-- Name: COLUMN baas_856064_system_config.created; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.created IS '创建时间';


--
-- Name: COLUMN baas_856064_system_config.updated; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.updated IS '修改时间';


--
-- Name: COLUMN baas_856064_system_config.tenant_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.tenant_id IS '租户ID';


--
-- Name: COLUMN baas_856064_system_config.project_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.project_id IS '项目ID';


--
-- Name: COLUMN baas_856064_system_config.key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.baas_856064_system_config.key IS 'key';


--
-- Name: baas_tc80ce3_p3124c3_system_config_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.baas_tc80ce3_p3124c3_system_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: baas_tc80ce3_p3124c3_system_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.baas_tc80ce3_p3124c3_system_config_id_seq OWNED BY public.baas_856064_system_config.id;


--
-- Name: baas_856064_activities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_activities ALTER COLUMN id SET DEFAULT nextval('public.baas_00403b_activities_id_seq'::regclass);


--
-- Name: baas_856064_positions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_positions ALTER COLUMN id SET DEFAULT nextval('public.baas_00403b_positions_id_seq'::regclass);


--
-- Name: baas_856064_system_config id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_system_config ALTER COLUMN id SET DEFAULT nextval('public.baas_tc80ce3_p3124c3_system_config_id_seq'::regclass);


--
-- Name: baas_856064_teams id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_teams ALTER COLUMN id SET DEFAULT nextval('public.baas_00403b_teams_id_seq'::regclass);


--
-- Name: baas_856064_user_activities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_user_activities ALTER COLUMN id SET DEFAULT nextval('public.baas_00403b_user_activities_id_seq'::regclass);


--
-- Name: baas_856064_users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_users ALTER COLUMN id SET DEFAULT nextval('public.baas_00403b_users_id_seq'::regclass);


--
-- Name: baas_856064_activities baas_00403b_activities____pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_activities
    ADD CONSTRAINT baas_00403b_activities____pkey PRIMARY KEY (id);


--
-- Name: baas_856064_activities baas_00403b_activities__uuid__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_activities
    ADD CONSTRAINT baas_00403b_activities__uuid__key UNIQUE (uuid);


--
-- Name: baas_856064_positions baas_00403b_positions____pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_positions
    ADD CONSTRAINT baas_00403b_positions____pkey PRIMARY KEY (id);


--
-- Name: baas_856064_positions baas_00403b_positions__uuid__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_positions
    ADD CONSTRAINT baas_00403b_positions__uuid__key UNIQUE (uuid);


--
-- Name: baas_856064_teams baas_00403b_teams____pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_teams
    ADD CONSTRAINT baas_00403b_teams____pkey PRIMARY KEY (id);


--
-- Name: baas_856064_teams baas_00403b_teams__uuid__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_teams
    ADD CONSTRAINT baas_00403b_teams__uuid__key UNIQUE (uuid);


--
-- Name: baas_856064_user_activities baas_00403b_user_activities____pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_user_activities
    ADD CONSTRAINT baas_00403b_user_activities____pkey PRIMARY KEY (id);


--
-- Name: baas_856064_user_activities baas_00403b_user_activities__uuid__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_user_activities
    ADD CONSTRAINT baas_00403b_user_activities__uuid__key UNIQUE (uuid);


--
-- Name: baas_856064_users baas_00403b_users____pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_users
    ADD CONSTRAINT baas_00403b_users____pkey PRIMARY KEY (id);


--
-- Name: baas_856064_users baas_00403b_users__uuid__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_users
    ADD CONSTRAINT baas_00403b_users__uuid__key UNIQUE (uuid);


--
-- Name: baas_856064_system_config baas_tc80ce3_p3124c3_system_config____pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_system_config
    ADD CONSTRAINT baas_tc80ce3_p3124c3_system_config____pkey PRIMARY KEY (id);


--
-- Name: baas_856064_system_config baas_tc80ce3_p3124c3_system_config__uuid__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.baas_856064_system_config
    ADD CONSTRAINT baas_tc80ce3_p3124c3_system_config__uuid__key UNIQUE (uuid);


--
-- Name: baas_00403b_activities__created__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_activities__created__idx ON public.baas_856064_activities USING btree (created);


--
-- Name: baas_00403b_activities__project_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_activities__project_id__idx ON public.baas_856064_activities USING btree (project_id);


--
-- Name: baas_00403b_activities__tenant_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_activities__tenant_id__idx ON public.baas_856064_activities USING btree (tenant_id);


--
-- Name: baas_00403b_positions__created__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_positions__created__idx ON public.baas_856064_positions USING btree (created);


--
-- Name: baas_00403b_positions__project_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_positions__project_id__idx ON public.baas_856064_positions USING btree (project_id);


--
-- Name: baas_00403b_positions__tenant_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_positions__tenant_id__idx ON public.baas_856064_positions USING btree (tenant_id);


--
-- Name: baas_00403b_teams__created__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_teams__created__idx ON public.baas_856064_teams USING btree (created);


--
-- Name: baas_00403b_teams__project_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_teams__project_id__idx ON public.baas_856064_teams USING btree (project_id);


--
-- Name: baas_00403b_teams__tenant_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_teams__tenant_id__idx ON public.baas_856064_teams USING btree (tenant_id);


--
-- Name: baas_00403b_user_activities__created__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_user_activities__created__idx ON public.baas_856064_user_activities USING btree (created);


--
-- Name: baas_00403b_user_activities__project_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_user_activities__project_id__idx ON public.baas_856064_user_activities USING btree (project_id);


--
-- Name: baas_00403b_user_activities__tenant_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_user_activities__tenant_id__idx ON public.baas_856064_user_activities USING btree (tenant_id);


--
-- Name: baas_00403b_users__created__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_users__created__idx ON public.baas_856064_users USING btree (created);


--
-- Name: baas_00403b_users__project_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_users__project_id__idx ON public.baas_856064_users USING btree (project_id);


--
-- Name: baas_00403b_users__tenant_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_00403b_users__tenant_id__idx ON public.baas_856064_users USING btree (tenant_id);


--
-- Name: baas_tc80ce3_p3124c3_system_config__created__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_tc80ce3_p3124c3_system_config__created__idx ON public.baas_856064_system_config USING btree (created);


--
-- Name: baas_tc80ce3_p3124c3_system_config__project_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_tc80ce3_p3124c3_system_config__project_id__idx ON public.baas_856064_system_config USING btree (project_id);


--
-- Name: baas_tc80ce3_p3124c3_system_config__tenant_id__idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX baas_tc80ce3_p3124c3_system_config__tenant_id__idx ON public.baas_856064_system_config USING btree (tenant_id);


--
-- Name: baas_856064_positions realtime_trigger_baas_856064_positions; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER realtime_trigger_baas_856064_positions AFTER INSERT OR DELETE OR UPDATE ON public.baas_856064_positions FOR EACH ROW EXECUTE FUNCTION public.notify_realtime_change();


--
-- Name: baas_856064_teams realtime_trigger_baas_856064_teams; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER realtime_trigger_baas_856064_teams AFTER INSERT OR DELETE OR UPDATE ON public.baas_856064_teams FOR EACH ROW EXECUTE FUNCTION public.notify_realtime_change();


--
-- Name: baas_856064_user_activities realtime_trigger_baas_856064_user_activities; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER realtime_trigger_baas_856064_user_activities AFTER INSERT OR DELETE OR UPDATE ON public.baas_856064_user_activities FOR EACH ROW EXECUTE FUNCTION public.notify_realtime_change();


--
-- Name: baas_856064_users realtime_trigger_baas_856064_users; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER realtime_trigger_baas_856064_users AFTER INSERT OR DELETE OR UPDATE ON public.baas_856064_users FOR EACH ROW EXECUTE FUNCTION public.notify_realtime_change();


--
-- PostgreSQL database dump complete
--


