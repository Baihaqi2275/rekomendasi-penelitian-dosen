from sqlalchemy import create_engine
import pandas as pd

try:
    engine = create_engine("mysql+pymysql://root:@127.0.0.1/db_siredo?charset=utf8mb4")
    df = pd.read_sql("SELECT 1", engine)
    print("Database connection successful")
except Exception as e:
    print(f"Database connection failed: {e}")
