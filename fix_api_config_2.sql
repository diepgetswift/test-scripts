update dbp_config set value = 'live' where name = 'tenant_env';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_live';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_staging';
    
update dbp_config set value = '019f194f-41df-7095-9e73-506c0807a825' where name = 'tenant_uuid';
