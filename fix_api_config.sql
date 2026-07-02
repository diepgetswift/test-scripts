update dbp_config set value = 'live' where name = 'tenant_env';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_live';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_staging';
