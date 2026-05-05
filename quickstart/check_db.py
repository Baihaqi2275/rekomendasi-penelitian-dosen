import pandas as pd
from sqlalchemy import create_engine

engine = create_engine("mysql+pymysql://root:@localhost/db_siredo")
df = pd.read_sql("SELECT * FROM publication LIMIT 1", engine)
print("Columns in publication table:", df.columns.tolist())
df_lecturer = pd.read_sql("SELECT * FROM lecturers LIMIT 1", engine)
print("Columns in lecturers table:", df_lecturer.columns.tolist())
