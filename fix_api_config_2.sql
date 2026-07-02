update dbp_config set value = 'live' where name = 'tenant_env';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_live';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_staging';
update dbp_config set value = '019f1957-3336-72bb-b88e-e82a8b6ee073' where name = 'tenant_uuid';
